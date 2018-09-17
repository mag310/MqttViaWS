<?php

namespace Intersvyaz\MqttViaWS\protocol\packet;

use Intersvyaz\MqttViaWS\protocol\Mqtt;

/**
 * Class mqttConnackPacket
 *
 * @package Intersvyaz\MqttViaWS\protocol\packet
 */
class mqttConnackPacket extends mqttBasePacket
{
    const CODE_ACCEPTED = 0x00;                     //Connection accepted
    const CODE_REFUSED_PROTOCOL_VERSION = 0x01;     //The Server does not support the level of the MQTT protocol requested by the Client
    const CODE_REFUSED_IDENTIFIER_REJECTED = 0x02;  //The Client identifier is correct UTF-8 but not allowed by the Server
    const CODE_REFUSED_SERVER_UNAVAILABLE = 0x03;   //The Network Connection has been made but the MQTT service is unavailable
    const CODE_REFUSED_BAD_USER_OR_PASSWORD = 0x04; //The data in the user name or password is malformed
    const CODE_REFUSED_NOT_AUTHORIZED = 0x05;       //The Client is not authorized to connect

    /** @var bool */
    public $sessionPresent;
    public $returnCode;

    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_CONNACK;

        if (empty($response)) {
            return $packet;
        }
        $len = unpack("Cb1/Cb2", $response);
        $packet->flags = $len['b1'] & 0b00001111;
        $packet->remainingLength = $len['b2'];

        $vHeader = substr($response, 2, $packet->remainingLength);

        $len = unpack("Cb1/Cb2", $vHeader);
        $packet->sessionPresent = (bool)($len['b1'] & 0b00000001);

        $packet->returnCode = $len['b2'];

        return $packet;
    }
}
