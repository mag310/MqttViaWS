<?php

namespace Intersvyaz\MqttViaWS\client;


require_once __DIR__ . "/../protocol/Mqtt.php";

use Intersvyaz\MqttViaWS\packet\mqttBasePacket;
use Intersvyaz\MqttViaWS\packet\mqttConnackPacket;
use Intersvyaz\MqttViaWS\packet\mqttConnectPacket;
use Intersvyaz\MqttViaWS\packet\mqttDisconnectPacket;
use Intersvyaz\MqttViaWS\packet\mqttPingreqPacket;
use Intersvyaz\MqttViaWS\packet\mqttPingrespPacket;
use Intersvyaz\MqttViaWS\packet\mqttPubackPacket;
use Intersvyaz\MqttViaWS\packet\mqttPublishPacket;
use Intersvyaz\MqttViaWS\packet\mqttSubackPacket;
use Intersvyaz\MqttViaWS\packet\mqttSubscribePacket;
use Intersvyaz\MqttViaWS\packet\mqttUnsubackPacket;
use Intersvyaz\MqttViaWS\protocol\Mqtt;
use Intersvyaz\MqttViaWS\wrapper\Websocket;

/*
	A simple php class to connect/publish to an MQTT broker
*/

class MqttClient
{
    /** @var Websocket */
    private $streamWrapper;

    /** @var int */
    private $msgId = 0;

    /** @var array */
    private $topics;

    /** @var bool */
    private $debugMode = false;

    /** @var int */
    private $keepAlive = 0xffff;        // default keepalive timer

    /** @var string */
    private $clientId;

    /** @var bool */
    private $clean;
    /** @var array */
    private $will;
    /** @var string */
    private $username;
    /** @var string */
    private $password;

    /**
     * Переводим поток в блокирующий режим
     */
    private function startTransaction()
    {
        return $this->streamWrapper->stream_set_option(STREAM_OPTION_BLOCKING, true);
    }

    /**
     * Переводим поток в неблокирующий режим
     */
    private function endTransaction()
    {
        return $this->streamWrapper->stream_set_option(STREAM_OPTION_BLOCKING, false);
    }

    /**
     * @param Websocket $newWrapper
     */
    public function setWrapper($newWrapper)
    {
        $this->disconnect();
        $this->streamWrapper = $newWrapper;
    }

    /**
     * @param string $newClientId
     */
    public function setClientId($newClientId)
    {
        $this->clientId = $newClientId;
    }

    /**
     * @param bool $newMode
     */
    public function setDebug($newMode)
    {
        $this->debugMode = $newMode;
    }

    /**
     * Подставляет кодированную длинну данных перед данными
     *
     * @param string $str
     * @return string
     */
    private function strWriteString($str)
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        return chr($msb) . chr($lsb) . $str;
    }

    /**
     * Рассчет длинны сообщения
     *
     * @param string $data
     * @return int
     */
    private function getRemainingLength($data)
    {
        $len = strlen($data);
        $string = "";
        do {
            $digit = $len % 128;
            $len = $len >> 7;
            if ($len > 0) {
                $digit = ($digit | 0x80);
            }
            $string .= chr($digit);
        } while ($len > 0);
        return $string;
    }

    /**
     * @param string $data
     * @return bool
     */
    private function send($data)
    {
        if (!$this->streamWrapper->isConnected()) {
            error_log('Поток не подключен!', E_WARNING);
            return false;
        }
        if (!$this->streamWrapper->sendData($data, Websocket::TYPE_BINARY)) {
            error_log('Ошибка при отправке!', E_WARNING);
            return false;
        }
        return true;
    }

    /**
     * @return string|bool
     */
    private function get()
    {
        if (!$this->streamWrapper->isConnected()) {
            trigger_error('Поток не подключен!', E_USER_ERROR);
            return false;
        }

        $response = $this->streamWrapper->getData();

        if ($response && $response['type'] == Websocket::TYPE_CLOSE) {
            $this->disconnect();
            $this->streamWrapper->disconnect();
            if ($this->debugMode) {
                echo 'WS соединение закрыто' . PHP_EOL;
            }
            return false;
        }

        if (!$response) {
            return false;
        }

        if ($response['type'] !== Websocket::TYPE_BINARY) {
            error_log('Некорректный ответ: ', E_WARNING);
            return false;
        }
        return $response['payload'];
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @param bool $clean
     * @param array $will
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function open($clean = true, $will = null, $username = null, $password = null)
    {
        $this->clean = $clean;
        $this->will = $will;
        $this->username = $username;
        $this->password = $password;

        return $this->connect();
    }

    /**
     * @return bool
     */
    private function connect()
    {
        if (!$this->streamWrapper->isConnected()) {
            trigger_error('Поток не подключен!', E_USER_ERROR);
            return false;
        }

        $this->startTransaction();
        $packet = mqttConnectPacket::instance();
        $packet->connectFlags =
            ((empty($this->username) ? 0 : 1) << 7) +
            ((empty($this->password) ? 0 : 1) << 6) +
            ((empty($this->will['retain']) ? 0 : 1) << 5) +
            ((empty($this->will['qos']) ? 0 : $this->will['qos']) << 3) +
            ((empty($this->will) ? 0 : 1) << 2) +
            ($this->clean << 1);

        $packet->keepAlive = $this->keepAlive;
        $packet->clientId = $this->clientId;

        if (!is_null($this->will)) {
            $packet->willTopic = $this->will['topic'];
            $packet->willMessage = $this->will['message'];
        }

        $packet->username = $this->username;
        $packet->password = $this->password;

        if (!$this->wrire($packet)) {
            error_log('Ошибка при отправке заголовка!', E_WARNING);
            return false;
        }
        /** @var mqttConnackPacket $packet */
        $packet = $this->read();

        $this->endTransaction();

        if ($packet->type !== Mqtt::PACKET_CONNACK) {
            error_log('Получен не CONNACK пакет!', E_WARNING);
            return false;
        }
        if ($packet->returnCode != mqttConnackPacket::CODE_ACCEPTED) {
            error_log('Connected error: ' . $packet->returnCode, E_WARNING);
            return false;
        }
        if ($this->debugMode) {
            echo "Connected to Broker\n";
        }

        return true;
    }

    /**
     * @return bool
     */
    public function disconnect()
    {
        if (!$this->streamWrapper || !$this->streamWrapper->isConnected()) {
            return true;
        }

        $packet = mqttDisconnectPacket::instance();
        if (!$this->wrire($packet)) {
            return false;
        }

        return true;// $this->streamWrapper->disconnect();
    }

    /**
     * @return bool
     */
    public function reopen()
    {
        //Проверим поток
        if (!$this->streamWrapper->reopen()) {
            return false;
        }

        if (!$this->connect()) {
            return false;
        }

        if ($this->topics) {
            if (!$this->subscribe($this->topics)) {
                return false;
            }
        }

        return true;
    }

    /* ping: sends a keep alive ping */
    public function ping()
    {
        $packet = mqttPingreqPacket::instance();
        $this->startTransaction();

        if (!$this->wrire($packet)) {
            return false;
        }

        /** @var mqttPingrespPacket */
        if (!$packet = $this->read()) {
            return false;
        }

        $res = $packet->type == Mqtt::PACKET_PINGRESP;
        if ($this->debugMode) {
            echo "MQTT ping " . ($res ? 'OK' : 'Error') . PHP_EOL;
        }

        $this->endTransaction();
        return $res;
    }

    /**
     * @param string $topic
     * @param string $content
     * @param int $qos
     * @param bool $dup Пакет ранее уже отправлялся
     * @param bool $retain
     * @return bool
     */
    public function publish($topic, $content, $qos = 0, $dup = false, $retain = false)
    {
        $this->msgId++;

        $packet = mqttPublishPacket::instance();
        $packet->tName = $topic;
        $packet->payload = $content;
        $packet->flags = ((int)$dup << 3) + ($qos << 1) + (int)$retain;
        if ($qos) {
            $packet->id = $this->msgId;
        }

        if ($qos) {
            $this->startTransaction();
        }

        if (!$this->wrire($packet)) {
            return false;
        }

        if ($qos == 1) {
            /** @var mqttPubackPacket $packet */
            $packet = $this->read();

            if ($packet->type != Mqtt::PACKET_PUBACK || $packet->id != $this->msgId) {
                return false;
            }
        } elseif ($qos == 2) { //PUBREC
            trigger_error('Пока не реализовано!', E_USER_ERROR);
            return false;
        }
        if ($qos) {
            $this->endTransaction();
        }

        return true;

    }

    /**
     * @return mixed
     */
    public function read()
    {
        $response = $this->get();
        if (empty($response)) {
            return false;
        }

        $paket = Mqtt::loadPacket($response);

        return $paket;
    }

    /**
     * @param mqttBasePacket $packet
     * @return bool
     */
    public function wrire($packet)
    {
        $data = Mqtt::packetToString($packet);
        return $this->send($data);
    }

    /* subscribe: subscribes to topics */
    /**
     * @param array $topics
     * @return bool
     */
    public function subscribe($topics)
    {
        $this->msgId++;

        /** @var mqttSubscribePacket $packet */
        $packet = mqttSubscribePacket::instance();

        $packet->id = $this->msgId;
        $packet->topicFilters = $topics;

        $this->startTransaction();

        if (!$this->wrire($packet)) {
            return false;
        }

        /** @var mqttSubackPacket $packet */
        if (!$packet = $this->read()) {
            return false;
        }

        $this->endTransaction();

        if ($packet->type != Mqtt::PACKET_SUBACK) {
            return false;
        }

        if ($packet->id != $this->msgId) {
            return false;
        }

        //Число и порядок топиков и строк в ответе должно совпадать
        reset($packet->topics);
        foreach ($topics as $topic) {
            if (isset($this->topics[$topic['filter']])) {
                $this->topics[$topic['filter']] = array_merge(
                    $this->topics[$topic['filter']],
                    current($packet->topics)
                );
            } else {
                $this->topics[$topic['filter']] = current($packet->topics);
                $this->topics[$topic['filter']]['filter'] = $topic['filter'];
            }

            if (next($packet->topics) === false) {
                break;
            }
        }

        if ($this->debugMode) {
            echo 'Подписан на ' . count($this->topics) . ' топиков' . PHP_EOL;
        }
        return true;
    }

    public function unsubscribe($topics, $qos = 0)
    {
        $this->msgId++;
        $body = chr($this->msgId >> 8);
        $body .= chr($this->msgId % 256);
        foreach ($topics as $num => $topic) {
            $body .= $this->strWriteString($topic['name']);
        }

        $head = "\xa2";
        $head .= $this->getRemainingLength($body);

        $this->startTransaction();

        if (!$this->send($head . $body)) {
            return false;
        }

        /** @var mqttUnsubackPacket $packet */
        $packet = $this->read();

        $this->endTransaction();

        if (!$packet->type = Mqtt::PACKET_UNSUBACK) {
            return false;
        }

        if ($packet->id != $this->msgId) {
            return false;
        }

        foreach ($topics as $num => $topic) {
            unset($this->topics[$num]);
        }

        return true;
    }

}
