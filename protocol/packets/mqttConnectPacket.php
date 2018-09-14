<?php

namespace Intersvyaz\MqttViaWS\packet;

use Intersvyaz\MqttViaWS\protocol\Mqtt;

class mqttConnectPacket extends mqttBasePacket
{
    /** @var null|static */
    private static $packet = null;

    /** @var string */
    public $protocolName = 'MQTT';

    /** @var int */
    public $protocolLevel = 0x04;

    /** @var int */
    public $connectFlags;

    /** @var string */
    public $keepAlive;

    /** @var string */
    public $clientId;

    /** @var string */
    public $willTopic;

    /** @var string */
    public $willMessage;

    /** @var string */
    public $username;

    /** @var string */
    public $password;

    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        if (empty($response)) {
            if (is_null(self::$packet)) {
                self::$packet = new static();
                self::$packet->type = Mqtt::PACKET_CONNECT;
            }
            return self::$packet;
        }

        /**
         * @todo Здесь должна быть реализована обработка загрузки пакета.
         * @todo Но так-как пока реализую клиента - оставлю пустым
         */
        $packet = new static();
        $packet->type = Mqtt::PACKET_CONNECT;

        return $packet;
    }
}
