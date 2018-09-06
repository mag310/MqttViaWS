<?php

namespace Intersvyaz\MqttViaWS\client;

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
    private $keepAlive = 10;        // default keepalive timer

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

    /* strwritestring: writes a string to a buffer */
    private function strWriteString($str)
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        $ret = chr($msb);
        $ret .= chr($lsb);
        $ret .= $str;
        return $ret;
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
            trigger_error('Поток не подключен!', E_USER_ERROR);
            return false;
        }
        if (!$this->streamWrapper->sendData($data, Websocket::TYPE_BINARY)) {
            trigger_error('Ошибка при отправке!', E_USER_ERROR);
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
                echo 'Соединение закрыто' . PHP_EOL;
            }
            return false;
        }

        if (!$response || $response['type'] !== Websocket::TYPE_BINARY) {
            trigger_error('Некорректный ответ: ', E_USER_ERROR);
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

        $buffer = "\x00\x04\x4d\x51\x54\x54\x04";
        //No Will
        $var = 0;
        if ($this->clean) {
            $var += 0b00000010; //2
        }
        //Add will info to header
        if (!is_null($this->will)) {
            $var += 0b00000100; //4                 // Set will flag
            $var += ($this->will['qos'] << 3);      //Set will qos
            if ($this->will['retain']) {
                $var += 0b00100000; //32            //Set will retain
            }
        }

        if (!is_null($this->password)) {            //Add password to header
            $var += 0b01000000; //64;
        }
        if (!is_null($this->username)) {            //Add username to header
            $var += 0b10000000; //128;
        }

        $buffer .= chr($var);
        //Keep alive
        $buffer .= chr($this->keepAlive >> 8);
        $buffer .= chr($this->keepAlive & 0xff);

        $buffer .= $this->strWriteString($this->clientId);

        //Adding will to payload
        if (!is_null($this->will)) {
            $buffer .= $this->strWriteString($this->will['topic']);
            $buffer .= $this->strWriteString($this->will['content']);
        }

        if ($this->username) {
            $buffer .= $this->strWriteString($this->username);
        }
        if ($this->password) {
            $buffer .= $this->strWriteString($this->password);
        }


        $len = $this->getRemainingLength($buffer);

        $head = chr(0x10) . $len;

        if (!$this->send($head . $buffer)) {
            trigger_error('Ошибка при отправке заголовка!', E_USER_ERROR);
            return false;
        }

        $connack = $this->get();

        $bytes = unpack('Cb1/Cb2', $connack);
        if ($bytes['b1'] != 0b00100000 || $bytes['b2'] != 0b0000000010) {
            trigger_error('Получен не CONNACK пакет!', E_USER_ERROR);
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
        if ($this->streamWrapper && $this->streamWrapper->isConnected()) {
            return $this->send("\xe0\x00");
        }
        return true;
    }

    /**
     * @return bool
     */
    public function reopen()
    {
        //Проверим поток
        if (!$this->streamWrapper->isConnected()) {
            if (!$this->streamWrapper->reopen()) {
                return false;
            }
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
        if (!$this->streamWrapper->checkConnection()) {
            if ($this->debugMode) {
                echo "WS ping: error" . PHP_EOL;
            }
            return false;
        }

        if (!$this->send("\xc0\x00")) {
            return false;
        }

        if (!$response = $this->get()) {
            return false;
        }

        $res = ($response == "\xd0\x00");

        if ($this->debugMode) {
            echo "MQTT ping " . ($res ? 'OK' : 'Error') . PHP_EOL;
        }

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
        $body = $this->strWriteString($topic);
        if ($qos) {
            $this->msgId++;
            $body .= chr($this->msgId >> 8);
            $body .= chr($this->msgId % 256);
        }
        $body .= $content;

        $head = "\x30";
        if ($qos) {
            if ($dup) {
                $head += 0b00001000;
            }
            $head += $qos << 1;
        }
        if ($retain) {
            $head += 1;
        }

        $head .= $this->getRemainingLength($body);

        if (!$this->send($head . $body)) {
            return false;
        }

        if ($qos) {
            $response = $this->get();
            if ($qos == 1) {//PUBACK
                $puback = unpack("Ctype/Clength/nId", $response);
                if (
                    $puback['type'] == 0x40 &&
                    $puback['length'] == 2 &&
                    $puback['Id'] == $this->msgId
                ) {
                    return true;
                }
            } elseif ($qos == 2) { //PUBREC
                trigger_error('Пока не реализовано!', E_USER_ERROR);
                return false;
            }
            return false;
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

        $publish = unpack("Cb1", $response);
        $fhead = chr($publish['b1']);
        $type = $fhead & 0b11110000;

        if (ord($type) != 0x30) {//PUBLISH
            return false;
        }
        $dup = (bool)($fhead & 0b00001000);
        $qos = $fhead & 0b00000110;
        $retain = (bool)($fhead & 0b00000001);


        $payload = substr($response, 1);

        $len = unpack("Cb1/Cb2", $payload);

        $rLen = $len['b1'] + $len['b2'];
        $payload = substr($payload, 2, $rLen);

        $len = unpack("Cb1/Cb2", $payload);
        $tnLen = $len['b1'] + $len['b2'];
        $tName = substr($payload, 2, $tnLen);

        $payload = substr($payload, 2 + $tnLen, $rLen);

        $len = unpack("Cb1/Cb2", $payload);
        $msgId = $len['b1'] + $len['b2'];

        $payload = substr($payload, 2);

        return [
            'id' => $msgId,
            'dup' => $dup,
            'qos' => $qos,
            'retain' => $retain,
            'topic' => $tName,
            'content' => $payload,
        ];
    }

    /* subscribe: subscribes to topics */
    /**
     * @param array $topics
     * @param int $qos
     * @return bool
     */
    public function subscribe($topics, $qos = 0)
    {
        $this->msgId++;
        $body = chr($this->msgId >> 8);
        $body .= chr($this->msgId % 256);
        foreach ($topics as $num => $topic) {
            $body .= $this->strWriteString($topic['name']);
            $body .= chr($topic["qos"]);
            $this->topics[$num] = $topic;
        }
        //$qos

        $head = "\x82";
        $head .= $this->getRemainingLength($body);

        if (!$this->send($head . $body)) {
            return false;
        }

        if (!$response = $this->get()) {
            return false;
        }

        $header = substr($response, 0, 4);
        $payload = substr($response, 4);

        $puback = unpack("Ctype/Clength/nId", $header);
        if ($puback['type'] != 0x90) {//SUBACK
            return false;
        }
        if ($puback['Id'] != $this->msgId) {
            return false;
        }

        //Число и порядок топиков и строк в ответе должно совпадать
        $i = 0;
        foreach ($topics as $num => $topic) {
            $char = ord($payload{$i});
            $this->topics[$num]['status'] = (bool)($char & 0x80) ? 'failure' : 'success';
            $this->topics[$num]['maxQos'] = ($char & 0x03);

            $i++;
        }

        if ($this->debugMode) {
            echo 'Подписался на ' . count($topic) . ' топиков' . PHP_EOL;
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

        if (!$this->send($head . $body)) {
            return false;
        }


        if (!$response = $this->get()) {
            return false;
        }

        $header = substr($response, 0, 4);

        $puback = unpack("Ctype/Clength/nId", $header);

        if ($puback['type'] != 0xb0 || $puback['length'] != 0x02) {//UNSUBACK
            return false;
        }
        if ($puback['Id'] != $this->msgId) {
            return false;
        }

        foreach ($topics as $num => $topic) {
            unset($this->topics[$num]);
        }

        return true;
    }

}
