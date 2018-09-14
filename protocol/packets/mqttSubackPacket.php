<?php

namespace Intersvyaz\MqttViaWS\packet;


use Intersvyaz\MqttViaWS\protocol\Mqtt;

/**
 * Class mqttSubackPacket
 *
 * @package Intersvyaz\MqttViaWS\packet
 */
class mqttSubackPacket extends mqttBasePacket
{
    /** @var array */
    public $topics;

    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_SUBACK;

        if (empty($response)) {
            return $packet;
        }

        $len = unpack("Cb1/Cb2/nId", $response);
        $packet->flags = $len['b1'] & 0b00001111;
        $packet->remainingLength = $len['b2'];
        $packet->id = $len['Id'];

        $payloadLength = $packet->remainingLength - 2;
        $payload = substr($response, 4, $payloadLength);

        for ($i = 0; $i < $payloadLength; $i++) {
            $char = ord($payload{$i});
            $packet->topics[$i]['status'] = (bool)($char & 0x80) ? 'failure' : 'success';
            $packet->topics[$i]['qos'] = ($char & 0x03);
        }

        return $packet;
    }
}
