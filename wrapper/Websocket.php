<?php

namespace Intersvyaz\MqttViaWS\wrapper;

/**
 * Stream wrapper for websocket client
 *
 */
class Websocket
{
    const MODE_DEBUG = 0x1;

    const TYPE_TEXT = 'text';
    const TYPE_BINARY = 'binary';
    const TYPE_CLOSE = 'close';
    const TYPE_PING = 'ping';
    const TYPE_PONG = 'pong';

    /** @var bool */
    private $debugMode = false;

    /** @var string */
    private $buffer = '';
    /** @var string */
    private $_protocol;
    /** @var string */
    private $_host;
    /** @var string */
    private $_port;
    /** @var string */
    private $_path;
    /** @var bool */
    private $_connected = false;
    /** @var array */
    private $opcodes = array(
        0x1 => self::TYPE_TEXT,
        0x2 => self::TYPE_BINARY,
        0x8 => self::TYPE_CLOSE,
        0x9 => self::TYPE_PING,
        0xa => self::TYPE_PONG,
    );

    /** @var string */
    private $origin;
    /** @var int */
    private $version;
    /** @var string */
    private $wsProtocol;
    /** @var string */
    private $extensions;

    /** @var int */
    public $errno;
    /** @var string */
    public $errstr;
    /** @var float */
    public $timeout = 15;
    /** @var int */
    public $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT;
    /** @var resource */
    public $context; //Задается автоматически системой

    /** @var resource */
    public $stream = null;


    private function getTypeName($opcode)
    {
        return $this->opcodes[$opcode] ?? null;
    }

    /**
     * @param int $length
     * @return string
     */
    private function _generateRandomString($length = 10)
    {
        try {
            if ($length % 2 == 0) {
                $halfLength = $length / 2;
                $randomString = bin2hex(random_bytes($halfLength));
            } else {
                $halfLength = ($length / 2) + 1;
                $randomString = bin2hex(random_bytes($halfLength));
                $randomString = substr($randomString, 0, $length);
            }
        } catch (\Exception $e) {
            trigger_error($e->getMessage(), E_USER_ERROR);
            die;
        }
        return $randomString;
    }

    /**
     * Закодировать данные для передачи
     *
     * @param string $payload
     * @param string $type
     * @param bool $masked
     * @return string
     */
    private function _hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $payloadLength = strlen($payload);
        switch ($type) {
            case self::TYPE_TEXT:
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            case self::TYPE_BINARY:
                // first byte indicates FIN, Binary-Frame (10000010):
                $frameHead[0] = 130;
                break;
            case self::TYPE_CLOSE:
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case self::TYPE_PING:
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case self::TYPE_PONG:
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }
        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                $this->disconnect();
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }

        if ($masked === true) {
            // generate a random mask:
            $mask = [];
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }
            $frameHead = array_merge($frameHead, $mask);
            $frame = implode('', $frameHead);
            for ($i = 0; $i < $payloadLength; $i++) {
                $frame .= $payload[$i] ^ $mask[$i % 4];
            }
        } else {
            $frame = implode('', $frameHead);
            for ($i = 0; $i < $payloadLength; $i++) {
                $frame .= $payload[$i];
            }
        }
        return $frame;
    }

    /**
     * Декодировать принятые данные
     *
     * @param string $data
     * @return array|bool
     */
    private function _hybi10Decode($data)
    {
        $mask = '';

        $hdr = unpack("Cb1/Cb2", $data);
        $data = substr($data, 2); //Отрежем начало

        $fin = (boolean)($hdr["b1"] & 0b10000000);
        if ($rsv = $hdr["b1"] & 0b01110000) {
            trigger_error('Ошибка передачи данных', E_USER_ERROR);
        }
        $opcode = $hdr["b1"] & 0b00001111;
        $isMasked = (boolean)($hdr["b2"] & 0b10000000);

        $payloadLength = $hdr["b2"] & 0b01111111;

        if ($payloadLength >= 126) {
            if ($payloadLength == 127) {
                if (strlen($data) < 8) {
                    $data .= fread($this->stream, 8 - strlen($data));
                }
                $payloadLength = hexdec(bin2hex($data));

                $data = substr($data, 8);
            } else {
                if (strlen($data) < 2) {
                    $data .= fread($this->stream, 2 - strlen($data));
                }
                $payloadLength = hexdec(bin2hex($data));
                $data = substr($data, 2);
            }
        }

        if ($isMasked) {
            if (strlen($data) < 4) {
                $data .= fread($this->stream, 4 - strlen($data));
            }
            $mask = substr($data, 0, 4);
            $data = substr($data, 4);
        }

        $nullNumber = 0;
        while (strlen($data) < $payloadLength) {
            if (!$size = (int)($payloadLength - strlen($data))) {
                break;
            }

            if (!$buf = fread($this->stream, $size)) {
                if ($nullNumber == 5) {
                    trigger_error('Читаем пустые данные', E_USER_ERROR);
                    die;
                }

                sleep(1);
                $nullNumber++;
            }
            $data .= $buf;
        }

        if ($isMasked) {
            $unmaskedPayload = '';
            for ($i = 0; $i < strlen($data); $i++) {
                $j = $i % 4;
                if (isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j];
                }
            }
            $data = $unmaskedPayload;
        }
        $decodedData['type'] = $this->getTypeName($opcode);
        $decodedData['payload'] = $data;


        if ($fin) {
            $this->buffer = '';
            return $decodedData;
        } else {
            $this->buffer .= $data;
            return false;
        }
    }

    /**
     * @return  string
     */
    private function getUrl()
    {
        return $this->_protocol . "://" . $this->_host . ":" . $this->_port . $this->_path;
    }

    /**
     * Создать новый поток по заданным параметрам
     *
     * @param string $url
     * @return bool;
     */
    private function createStream($url)
    {
        if (!$this->context) {
            $this->context = stream_context_create();
        }

        $this->stream = stream_socket_client(
            $url,
            $this->errno,
            $this->errstr,
            $this->timeout,
            $this->flags,
            $this->context
        );

        if (!is_resource($this->stream)) {
            trigger_error("Connection error:\t{$this->errno}:\t{$this->errstr}", E_USER_ERROR);
        }
//
//        if (!stream_set_timeout($this->stream, $this->timeout)) {
//            error_log("Set timeout error:\t{$this->errno}:\t{$this->errstr}");
//            return false;
//        };

//        if (!stream_set_blocking($this->stream, false)) {
//            error_log("Set blocking error:\t{$this->errno}:\t{$this->errstr}");
//            return false;
//        };

        if ($this->debugMode) {
            echo 'Открыт новый поток' . PHP_EOL;
        }
        return true;

    }

    /** @inheritdoc */
    public function __construct()
    {
        $this->timeout = ini_get("default_socket_timeout");
    }

    /** @inheritdoc */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * @param resource $newContext
     */
    public function setContext($newContext)
    {
        $this->context = $newContext;
    }

    /**
     * Получить данные
     *
     * @return bool|array
     */
    public function getData()
    {
        if (!$res = fread($this->stream, 2)) {
            return false;
        }

        return $this->_hybi10Decode($res);
    }

    /**
     * @param string $data
     * @param string $type
     * @param bool $masked
     * @return bool
     */
    public function sendData($data, $type = 'text', $masked = true)
    {
        if ($this->_connected === false) {
            trigger_error("Not connected", E_USER_WARNING);
            return false;
        }
        if (!is_string($data)) {
            trigger_error("Not a string data was given.", E_USER_WARNING);
            return false;
        }
        if (strlen($data) == 0) {
            return false;
        }
        $res = fwrite($this->stream, $this->_hybi10Encode($data, $type, $masked));
        if ($res === 0 || $res === false) {
            return false;
        }
        if ($this->debugMode) {
            if ($type == self::TYPE_TEXT) {
                echo 'sending: ' . $data . PHP_EOL;
            } else {
                echo 'sending: ' . bin2hex($data) . PHP_EOL;
            }
        }
        return true;
    }

    /**
     * @param string $path
     * @param array $params
     * @return bool
     */
    public function open($path, $params)
    {
        $this->debugMode = $params['debug'] ?? false;

        $this->origin = $params['Origin'] ?? null;
        $this->version = $params['Version'] ?? null;
        $this->wsProtocol = $params['Protocol'] ?? null;
        $this->extensions = $params['Extensions'] ?? null;

        $url = parse_url($path);
        $isSsl = $url['scheme'] == 'wss';

        $this->_protocol = $isSsl ? "ssl" : "tcp";
        $this->_host = $url['host'];
        $this->_path = $url['path'] ?? "/";

        if (empty($url['port'])) {
            $this->_port = $isSsl ? 443 : 80;
        } else {
            $this->_port = $url['port'];
        }

        $url = $this->getUrl();
        if ($this->debugMode) {
            echo 'Connected to url: ' . $url . PHP_EOL;
        }

        if (!$this->createStream($url)) {
            return false;
        }
        return $this->connect();
    }

    /**
     * Переподключиться
     *
     * @return bool
     */
    public function reopen()
    {
        if (!$this->disconnect()) {
            trigger_error('Ошибка при отключении потока', E_USER_ERROR);
        }

        $url = $this->getUrl();
        if ($this->debugMode) {
            echo 'Reconnected to url: ' . $url . PHP_EOL;
        }

        if (!$this->createStream($url)) {
            return false;
        }
        return $this->connect();
    }

    /**
     * Подключиться
     *
     * @return bool
     */
    private function connect()
    {
        $header = "GET " . $this->_path . " HTTP/1.1\r\n";
        $header .= "Host: " . $this->_host . ":" . $this->_port . "\r\n";
        $header .= "Connection: Upgrade\r\n";
        $header .= "Upgrade: websocket\r\n";

        if ($this->origin) {
            $header .= "Origin: " . $this->origin . "\r\n";
        }
        if ($this->version) {
            $header .= "Sec-WebSocket-Version: {$this->version}\r\n";
        }
        if ($this->extensions) {
            $header .= "Sec-WebSocket-Extensions: {$this->extensions}\r\n";
        }
        if ($this->wsProtocol) {
            $header .= "Sec-WebSocket-Protocol: {$this->wsProtocol}\r\n";
        }

        $key = base64_encode($this->_generateRandomString(16));
        $header .= "Sec-WebSocket-Key: " . $key . "\r\n";
        $header .= "\r\n";

        $len = strlen($header);
        if ($len !== fwrite($this->stream, $header, $len)) {
            trigger_error('Заголовок не отправился', E_USER_ERROR);
            die;
        }

        $response = fread($this->stream, 1500);
        preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches);
        if ($matches) {
            $keyAccept = trim($matches[1]);
            $hash = sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11');
            $expectedResonse = base64_encode(hex2bin($hash));

            $this->_connected = ($keyAccept === $expectedResonse) ? true : false;
        }
        if ($this->debugMode) {
            echo 'connect: ' . ($this->_connected ? 'Ok' : 'Error') . PHP_EOL;
        }
        return $this->_connected;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->_connected;
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
        $this->_connected = false;
        if (!is_resource($this->stream)) {
            return false;
        }

        $data = $this->_hybi10Encode('ping?', 'ping', false);
        if (strlen($data) !== fwrite($this->stream, $data, strlen($data))) {
            return false;
        }

        if (!$response = fread($this->stream, 300)) {
            return false;
        }

        $response = $this->_hybi10Decode($response);
        if (!is_array($response) || !isset($response['type']) || $response['type'] !== 'pong') {
            return false;
        }

        $this->_connected = true;
        if ($this->debugMode) {
            echo 'WS ping: Ok' . PHP_EOL;
        }
        return true;
    }

    /**
     * Оключиться
     */
    public function disconnect()
    {
        $this->_connected = false;
        if (!is_resource($this->stream)) {
            return true;
        }

        return fclose($this->stream);
    }

    /**
     * Открывает или URL
     *
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string &$opened_path
     * @return bool
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $contextParams = stream_context_get_options($this->context);
        if (isset($contextParams['ws'])) {
            $params = $contextParams['ws'];
        } else {
            $params = [];
        }

        if ($mode & self::MODE_DEBUG) {
            $params['debug'] = true;
        }

        $res = $this->open($path, $params);
        if ($res && ($options & STREAM_USE_PATH)) {
            $opened_path = $this->getUrl();
        }
        return $res;
    }

    /**
     *  Запись в поток
     *
     * @param string $data
     * @return bool|int
     */
    public function stream_write($data)
    {
        if (!$this->checkConnection()) {
            return false;
        }

        if ($this->sendData($data)) {
            return strlen($data);
        }
        return false;
    }

    /**
     * Читает из потока
     *
     * @return string
     */
    public function stream_read()
    {
        return $this->getData();
    }

    /**
     * Проверяет достижение конца файла по файловому указателю
     *
     * @return bool
     */
    public function stream_eof()
    {
        return feof($this->stream);
    }

    /**
     * Получает ресурс уровнем ниже
     *
     * @param integer $cast_as
     * @return bool|resource
     */
    public function stream_cast($cast_as)
    {
        if ($cast_as == STREAM_CAST_FOR_SELECT || $cast_as == STREAM_CAST_AS_STREAM) {
            return $this->stream ?? false;
        }
        return false;
    }

    /**
     * Получение информации о файловом ресурсе
     *
     * @return array
     */
    public function stream_stat()
    {
        return stream_get_meta_data($this->stream);
    }

    /**
     * Изменение настроек потока
     *
     * @param int $option
     * @param int $arg1
     * @param int $arg2
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                return stream_set_blocking($this->stream, $arg1);
            case STREAM_OPTION_READ_TIMEOUT:
                $this->timeout = $arg1;
                return stream_set_timeout($this->stream, $arg1, $arg2);
            case STREAM_OPTION_WRITE_BUFFER:
                return stream_set_write_buffer($this->stream, $arg2);
        }
        return false;
    }
}

stream_wrapper_register("ws", Websocket::class) or die("Failed to register protocol");
stream_wrapper_register("wss", Websocket::class) or die("Failed to register protocol");
