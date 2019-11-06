<?php

namespace Intersvyaz\MqttViaWS\client;


require_once __DIR__ . "/../protocol/Mqtt.php";

use Intersvyaz\MqttViaWS\protocol\packet\mqttBasePacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttConnackPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttConnectPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttDisconnectPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttPingreqPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttPingrespPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttPubackPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttPublishPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttSubackPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttSubscribePacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttUnsubackPacket;
use Intersvyaz\MqttViaWS\protocol\packet\mqttUnsubscribePacket;
use Intersvyaz\MqttViaWS\protocol\Mqtt;
use Intersvyaz\MqttViaWS\wrapper\Websocket;

/*
	A simple php class to connect/publish to an MQTT broker
*/

class MqttClient
{
    /** @var null | callable */
    public $sender = null;          //Функция, формирующая пакет на отправку

    /** @var null | callable */
    public $onConnected = null;

    /** @var null | callable */
    public $onPublish = null;

    /** @var null | callable */
    public $onDisconnect = null;

    /** @var null | callable */
    public $onPingResp = null;

    /** @var null | callable */
    public $onSubscribe = null;

    /** @var null | callable */
    public $onUnsubscribe = null;

    /** @var bool */
    public $blockedMode = false;

    /**
     * Ждем ответа от пингера
     *
     * @var bool
     */
    private $waitPing = false;

    /**
     * Статус установки соединения
     *
     * @var bool
     */
    private $isConnected = false;

    /** @var resource */
    private $stream;

    /** @var int */
    private $msgId = 0;

    /** @var array */
    public $topics;

    /**
     * @var mqttBasePacket[]
     */
    public $pakage = [];

    /** @var bool */
    private $debugMode = false;

    /** @var int */
    public $keepAlive = 0xffff;        // default keepalive timer

    /** @var int */
    public $timeOut = 10;

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
     * Обработка SUBAСK пакета
     *
     * @param mqttSubackPacket $ackPacket
     * @return bool
     */
    private function suback($ackPacket)
    {
        if (
            !isset($this->pakage[$ackPacket->id]) ||
            !$this->pakage[$ackPacket->id] instanceof mqttSubscribePacket
        ) {
            return false;
        }

        /** @var mqttSubscribePacket $packet */
        $packet = $this->pakage[$ackPacket->id];

        reset($ackPacket->topics);
        foreach ($packet->topicFilters as $topic) {
            if (isset($this->topics[$topic['filter']])) {
                $this->topics[$topic['filter']] = array_merge(
                    $this->topics[$topic['filter']],
                    current($ackPacket->topics)
                );
            } else {
                $this->topics[$topic['filter']] = current($ackPacket->topics);
                $this->topics[$topic['filter']]['filter'] = $topic['filter'];
            }

            if (next($ackPacket->topics) === false) {
                break;
            }
        }
        unset($this->pakage[$ackPacket->id]);

        return true;
    }

    /***
     * @param mqttUnsubackPacket $ackPacket
     * @return bool
     */
    private function unsuback($ackPacket)
    {
        if (
            !isset($this->pakage[$ackPacket->id]) ||
            !$this->pakage[$ackPacket->id] instanceof mqttUnsubscribePacket
        ) {
            return false;
        }

        /** @var mqttUnsubscribePacket $packet */
        $packet = $this->pakage[$ackPacket->id];

        foreach ($packet->topicFilters as $topic) {
            if (isset($this->topics[$topic['filter']])) {
                unset($this->topics[$topic['filter']]);
            }
        }
        unset($this->pakage[$ackPacket->id]);

        return true;
    }

    /**
     * обработка PUBACK пакета
     *
     * @param mqttPubackPacket $ackPacket
     * @return bool
     */
    private function pubask($ackPacket)
    {
        if (
            !isset($this->pakage[$ackPacket->id]) ||
            !$this->pakage[$ackPacket->id] instanceof mqttPublishPacket
        ) {
            return false;
        }

        unset($this->pakage[$ackPacket->id]);
        return true;
    }

    /**
     * @param string $data
     * @return bool
     */
    private function send($data)
    {
        if (!$res = fwrite($this->stream, $data)) {
            trigger_error('Ошибка при отправке!', E_USER_WARNING);
            return false;
        }
        return true;
    }

    /**
     * @return string|bool
     */
    private function get()
    {
        $response = fread($this->stream, 4096);

        if ($response === false) {
            $this->disconnect();
            return false;
        }

        return $response;
    }


    /**
     * @return bool
     */
    private function connect()
    {
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
            trigger_error('Ошибка при отправке заголовка!', E_USER_WARNING);
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->isConnected;
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
    public function open($url, $params = [])
    {
        $this->clean = $params['clean'] ?? true;
        $this->will = $params['will'] ?? null;
        $this->username = $params['username'] ?? null;
        $this->password = $params['password'] ?? null;


        if (strpos($url, 'tcp:') === 0) {
            $this->stream = stream_socket_client(
                $url,
                $errno,
                $errstr,
                15,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_PERSISTENT
            );
        } elseif (strpos($url, 'ws') === 0) {
            $context = stream_context_create([
                'ws' => [
                    'debug' => $this->debugMode,
                    'Origin' => 'mqtt-client://' . $this->clientId,
                    'Version' => 13,
                    'Protocol' => 'mqtt',
                ]
            ]);
            $this->stream = fopen($url, Websocket::TYPE_BINARY, false, $context);
        } else {
            $this->stream = fopen($url, 'rw+');
        }


        return $this->connect();
    }

    /**
     * @return bool
     */
    public function disconnect()
    {
        if (!is_resource($this->stream)) {
            return true;
        }

        $packet = mqttDisconnectPacket::instance();
        if (!$this->wrire($packet)) {
            return false;
        }
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->isConnected = false;
        return true;
    }

    /**
     * @return bool
     */
    public function reopen()
    {
        $this->waitPing = false;

        //Проверим поток
        if (!$this->disconnect()) {
            return false;
        }

        if (!$this->connect()) {
            return false;
        }

        if (!empty($this->topics)) {
            $packet = $this->createSubscribePacket($this->topics);
            if ($this->wrire($packet)) {
                return false;
            }
        }

        return true;
    }

    /* ping: sends a keep alive ping */
    public function ping()
    {
        if ($this->waitPing) {
            return false;
        }

        $packet = mqttPingreqPacket::instance();
        return $this->wrire($packet);
    }

    /**
     * @return mqttBasePacket | null | bool
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
        if (!$this->isConnected && $packet->type != Mqtt::PACKET_CONNECT) {
            return false;
        }

        $data = Mqtt::packetToString($packet);
        return $this->send($data);
    }

    /**
     * @param string $topic
     * @param string $content
     * @param int $qos
     * @param bool $dup Пакет ранее уже отправлялся
     * @param bool $retain
     * @return mqttPublishPacket
     */
    public function createPublishPacket($topic, $content, $qos = 0, $dup = false, $retain = false)
    {
        $packet = mqttPublishPacket::instance();
        $packet->tName = $topic;
        $packet->payload = $content;
        $packet->flags = ((int)$dup << 3) + ($qos << 1) + (int)$retain;
        if ($qos) {
            $this->msgId++;
            $packet->id = $this->msgId;
            $this->pakage[$packet->id] = $packet;
        }
        return $packet;
    }

    /**
     * @param array $topics
     * @return mqttSubscribePacket
     */
    public function createSubscribePacket($topics)
    {
        $this->msgId++;

        /** @var mqttSubscribePacket $packet */
        $packet = mqttSubscribePacket::instance();

        $packet->id = $this->msgId;
        $packet->topicFilters = $topics;

        $this->pakage[$this->msgId] = $packet;
        return $packet;
    }

    /**
     * @param array $topics
     * @return mqttUnsubscribePacket
     */
    public function createUnsubscribePacket($topics)
    {
        $this->msgId++;
        $packet = mqttUnsubscribePacket::instance();

        $packet->id = $this->msgId;
        $packet->topicFilters = $topics;

        $this->pakage[$this->msgId] = $packet;
        return $packet;
    }

    /**
     *  Основная петля
     */
    public function run()
    {
        while (true) {
            if (!$this->blockedMode) {
                stream_set_blocking($this->stream, false);
            }

            $startTime = microtime(true);

            if (!$this->blockedMode) {
                while (!$res = $this->read()) {

                    if (is_callable($this->sender) && $packet = call_user_func($this->sender, $this)) {
                        if ($this->wrire($packet)) {
                            $startTime = microtime(true);
                        }
                    } elseif (microtime(true) - $startTime > $this->timeOut) {
                        while (!$this->ping()) {
                            if (microtime(true) - $startTime > $this->keepAlive) {
                                trigger_error('TimeOut error');
                            }
                            if ($this->debugMode) {
                                echo 'Ping error' . PHP_EOL;
                            }
                            sleep($this->timeOut);
                            $this->reopen();
                        }

                        $startTime = microtime(true);
                    }
                }

            } else {
                $res = $this->read();
            }

            if (!$res) {
                continue;
            }

            if (!$this->isConnected && $res->type !== Mqtt::PACKET_CONNACK) {
                continue;
            }

            switch ($res->type) {
                case Mqtt::PACKET_CONNACK:
                    /** @var mqttConnackPacket $res */
                    if ($res->returnCode != mqttConnackPacket::CODE_ACCEPTED) {
                        trigger_error('Connected error: ' . $res->returnCode, E_USER_ERROR);
                        return false;
                    }

                    $this->isConnected = true;

                    if (is_callable($this->onConnected)) {
                        call_user_func($this->onConnected, $res);
                    }
                    break;
                case Mqtt::PACKET_PUBLISH:
                    if (is_callable($this->onPublish)) {
                        call_user_func($this->onPublish, $res);
                    }
                    break;
                case Mqtt::PACKET_PINGRESP:
                    $this->waitPing = false;

                    if (is_callable($this->onPingResp)) {
                        call_user_func($this->onPingResp, $res);
                    }
                    break;
                case Mqtt::PACKET_DISCONNECT:
                    if (is_callable($this->onDisconnect)) {
                        call_user_func($this->onDisconnect, $res);
                    }
                    $this->disconnect();
                    return false;
                case Mqtt::PACKET_SUBACK:
                    /** @var mqttSubackPacket $res */
                    if (!$this->suback($res)) {
                        $res = false;
                    }

                    if (is_callable($this->onSubscribe)) {
                        call_user_func($this->onSubscribe, $res);
                    }
                    break;
                case Mqtt::PACKET_UNSUBACK:
                    /** @var mqttUnsubackPacket $res */
                    if (!$this->unsuback($res)) {
                        $res = false;
                    }

                    if (is_callable($this->onUnsubscribe)) {
                        call_user_func($this->onUnsubscribe, $res);
                    }
                    break;
                case Mqtt::PACKET_PUBACK:
                    /** @var mqttPubackPacket $res */
                    if (!$this->pubask($res)) {
                        $res = false;
                    }

                    if (is_callable($this->onPublish)) {
                        call_user_func($this->onPublish, $res);
                    }
                    break;
            }
        }
    }
}
