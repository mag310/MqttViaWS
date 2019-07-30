<?php

namespace Intersvyaz\MqttViaWS\protocol\packet;

use Intersvyaz\MqttViaWS\protocol\Mqtt;

/**
 * Class mqttPublishPacket
 *
 * @package Intersvyaz\MqttViaWS\protocol\packet
 */
class mqttPublishPacket extends mqttBasePacket
{
    /** @var bool */
    public $dup;
    /** @var int */
    public $qos;
    /** @var bool */
    public $retain;
    /** @var string */
    public $tName;
    /** @var string */
    public $payload;

    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_PUBLISH;

        if (empty($response)) {
            return $packet;
        }

        $head = unpack("Cb1", $response);
        $fhead = chr($head['b1']);

        $packet->flags = $head['b1'] & 0b00001111;

        $packet->dup = (bool)($fhead & 0b00001000);
        $packet->qos = ($fhead & 0b00000110) >> 1;
        $packet->retain = (bool)($fhead & 0b00000001);

        $payload = substr($response, 1);

        $len = unpack('Cb1/Cb2/Cb3/Cb4', $payload);
        $packet->remainingLength = $len['b1'];
        $startByte = 1;
        if ($len['b1'] >= 127) {
            $packet->remainingLength = $packet->remainingLength * 128 + $len['b2'];
            $startByte = 2;
            if ($len['b2'] >= 127) {
                $packet->remainingLength = $packet->remainingLength * 128 + $len['b3'];
                $startByte = 3;
                if ($len['b3'] >= 127) {
                    $packet->remainingLength = $packet->remainingLength * 128 + $len['b4'];
                    $startByte = 4;
                }
            }
        }

        $payload = substr($payload, $startByte, $packet->remainingLength);

        $len = unpack("Cb1/Cb2", $payload);
        $tnLen = $len['b1'] + $len['b2'];
        $packet->tName = substr($payload, 2, $tnLen);

        $payload = substr($payload, 2 + $tnLen, $packet->remainingLength - $tnLen - 2);

        $len = unpack("Cb1/Cb2", $payload);
        $packet->id = $len['b1'] + $len['b2'];

        $packet->payload = substr($payload, 2, $packet->remainingLength - $tnLen - 2 - 2);

        return $packet;
    }

}
