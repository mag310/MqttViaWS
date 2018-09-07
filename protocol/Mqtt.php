<?php

namespace Intersvyaz\MqttViaWS\protocol;

require_once __DIR__ . "/../packets/basePacket.php";
require_once __DIR__ . "/../packets/mqttBasePacket.php";
require_once __DIR__ . "/../packets/mqttConnectPacket.php";
require_once __DIR__ . "/../packets/mqttConnackPacket.php";
require_once __DIR__ . "/../packets/mqttDisconnectPacket.php";
require_once __DIR__ . "/../packets/mqttPublishPacket.php";
require_once __DIR__ . "/../packets/mqttPubackPacket.php";
require_once __DIR__ . "/../packets/mqttPingrespPacket.php";
require_once __DIR__ . "/../packets/mqttPingreqPacket.php";
require_once __DIR__ . "/../packets/mqttSubackPacket.php";
require_once __DIR__ . "/../packets/mqttSubscribePacket.php";
require_once __DIR__ . "/../packets/mqttUnsubackPacket.php";

use Intersvyaz\MqttViaWS\packet\mqttBasePacket;
use Intersvyaz\MqttViaWS\packet\mqttConnackPacket;
use Intersvyaz\MqttViaWS\packet\mqttConnectPacket;
use Intersvyaz\MqttViaWS\packet\mqttDisconnectPacket;
use Intersvyaz\MqttViaWS\packet\mqttPingreqPacket;
use Intersvyaz\MqttViaWS\packet\mqttPubackPacket;
use Intersvyaz\MqttViaWS\packet\mqttPublishPacket;
use Intersvyaz\MqttViaWS\packet\mqttPingrespPacket;
use Intersvyaz\MqttViaWS\packet\mqttSubackPacket;
use Intersvyaz\MqttViaWS\packet\mqttSubscribePacket;
use Intersvyaz\MqttViaWS\packet\mqttUnsubackPacket;


/**
 * Class Mqtt
 */
class Mqtt
{
    const PACKET_CONNECT = 0x10;
    const PACKET_CONNACK = 0x20;
    const PACKET_PUBLISH = 0x30;
    const PACKET_PUBACK = 0x40;
    const PACKET_PUBREC = 0x50;
    const PACKET_PUBREL = 0x60;
    const PACKET_PUBCOMP = 0x70;
    const PACKET_SUBSCRIBE = 0x80;
    const PACKET_SUBACK = 0x90;
    const PACKET_UNSUBSCRIBE = 0xa0;
    const PACKET_UNSUBACK = 0xb0;
    const PACKET_PINGREQ = 0xc0;
    const PACKET_PINGRESP = 0xd0;
    const PACKET_DISCONNECT = 0xe0;

    /**
     * @param string $response
     * @return mqttBasePacket | null
     */
    public static function loadPacket($response)
    {
        $head = unpack("Cb1", $response);
        $type = $head['b1'] & 0b11110000;

        $packet = null;
        switch ($type) {
            case self::PACKET_CONNECT:
                return mqttConnectPacket::instance($response);
            case self::PACKET_DISCONNECT:
                return mqttDisconnectPacket::instance($response);
            case self::PACKET_CONNACK:
                return mqttConnackPacket::instance($response);
            case self::PACKET_PUBLISH:
                return mqttPublishPacket::instance($response);
            case self::PACKET_PUBACK:
                return mqttPubackPacket::instance($response);
            case self::PACKET_SUBSCRIBE:
                return mqttSubscribePacket::instance($response);
            case self::PACKET_SUBACK:
                return mqttSubackPacket::instance($response);
            case self::PACKET_UNSUBACK:
                return mqttUnsubackPacket::instance($response);
            case self::PACKET_PINGREQ:
                return mqttPingreqPacket::instance($response);
            case self::PACKET_PINGRESP:
                return mqttPingrespPacket::instance($response);
        }

        return $packet;
    }

    /**
     * @param mqttBasePacket $packet
     * @return string
     */
    public static function packetToString($packet)
    {
        $body = '';

        switch ($packet->type) {
            case self::PACKET_CONNECT:
                /** @var mqttConnectPacket $packet */

                $vHead = self::msbLsbCreate($packet->protocolName) . $packet->protocolName;
                $vHead .= chr($packet->protocolLevel);
                $vHead .= chr($packet->connectFlags);
                $vHead .= self::msbLsbCreate($packet->keepAlive);

                $payload = self::msbLsbCreate($packet->clientId) . $packet->clientId;
                if ($packet->willTopic) {
                    $payload .= self::msbLsbCreate($packet->willTopic) . $packet->willTopic;
                }
                if ($packet->willMessage) {
                    $payload .= self::msbLsbCreate($packet->willMessage) . $packet->willMessage;
                }
                if ($packet->username) {
                    $payload .= self::msbLsbCreate($packet->username) . $packet->username;
                }
                if ($packet->password) {
                    $payload .= self::msbLsbCreate($packet->password) . $packet->password;
                }
                $body = $vHead . $payload;
                break;
            case self::PACKET_PUBLISH:
                /** @var mqttPublishPacket $packet */
                $vHead = self::msbLsbCreate($packet->tName) . $packet->tName;
                if ($packet->id) {
                    $vHead .= self::msbLsbToIntCreate($packet->id);
                }
                $body = $vHead . $packet->payload;
                break;
            case self::PACKET_SUBSCRIBE:
                /** @var mqttSubscribePacket $packet */
                $vHead = self::msbLsbToIntCreate($packet->id);
                $payload = '';
                foreach ($packet->topicFilters as $filter) {
                    if (!isset($filter['filter'])) {
                        continue;
                    }
                    $payload .= self::msbLsbCreate($filter['filter']) . $filter['filter'];
                    $payload .= chr($filter["qos"] ?? 0);
                }

                $body = $vHead . $payload;
                break;
            case self::PACKET_CONNACK:  //Тело пакета - пустое
            case self::PACKET_PUBACK:
            case self::PACKET_PUBREC:
            case self::PACKET_PUBREL:
            case self::PACKET_PUBCOMP:
            case self::PACKET_UNSUBACK:
            case self::PACKET_PINGREQ:
            case self::PACKET_PINGRESP:
            case self::PACKET_DISCONNECT:
            default:
                $body = '';

                break;
        }

        $head = chr($packet->type | $packet->flags);
        $head .= self::getRemainingLength($body);

        return $head . $body;

    }


    /**
     * @param string $data
     * @return string
     */
    private static function getRemainingLength($data)
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
     * Подставляет кодированную длинну данных перед данными
     *
     * @param string $str
     * @return string
     */
    private static function msbLsbCreate($str)
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        return chr($msb) . chr($lsb);
    }

    /**
     * @param $str
     * @return string
     */
    private static function msbLsbToIntCreate($len)
    {
        $msb = $len >> 8;
        $lsb = $len % 256;
        return chr($msb) . chr($lsb);
    }
}
