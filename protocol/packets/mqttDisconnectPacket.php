<?php

namespace Intersvyaz\MqttViaWS\packet;


use Intersvyaz\MqttViaWS\protocol\Mqtt;

class mqttDisconnectPacket extends mqttBasePacket
{
    /** @var null|static */
    private static $packet = null;

    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        if (empty($response)) {
            if (is_null(self::$packet)) {
                self::$packet = new static();
                self::$packet->type = Mqtt::PACKET_DISCONNECT;
            }
            return self::$packet;
        }

        $packet = new static();
        $packet->type = Mqtt::PACKET_DISCONNECT;

        $len = unpack("Cb1/Cb2", $response);
        $packet->flags = $len['b1'] & 0b00001111;
        $packet->remainingLength = $len['b2'];

        return $packet;
    }

}
