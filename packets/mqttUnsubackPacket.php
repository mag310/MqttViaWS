<?php

namespace Intersvyaz\MqttViaWS\packet;

require_once __DIR__ . '/../protocol/Mqtt.php';

use Intersvyaz\MqttViaWS\protocol\Mqtt;

/**
 * Class mqttUnsubackPacket
 *
 * @package Intersvyaz\MqttViaWS\packet
 */
class mqttUnsubackPacket extends mqttBasePacket
{
    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_UNSUBACK;

        if (empty($response)) {
            return $packet;
        }

        $len = unpack("Cb1/Cb2/nId", $response);
        $packet->flags = $len['b1'] & 0b00001111;
        $packet->remainingLength = $len['b2'];
        $packet->id = $len['Id'];

        return $packet;
    }
}