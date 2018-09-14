<?php

namespace Intersvyaz\MqttViaWS\packet;


use Intersvyaz\MqttViaWS\protocol\Mqtt;

/**
 * Class mqttConnackPacket
 *
 * @package Intersvyaz\MqttViaWS\packet
 */
class mqttPingrespPacket extends mqttBasePacket
{
    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_PINGRESP;

        if (empty($response)) {
            return $packet;
        }

        $len = unpack("Cb1/Cb2", $response);
        $packet->flags = $len['b1'] & 0b00001111;
        $packet->remainingLength = $len['b2'];

        return $packet;
    }
}
