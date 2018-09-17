<?php

namespace Intersvyaz\MqttViaWS\protocol\packet;

/**
 * Класс для Mqtt пакетов
 * Class mqttBasePacket
 *
 * @package Intersvyaz\MqttViaWS\protocol\packet
 */
abstract class mqttBasePacket
{
    /** @var int */
    public $type;
    /** @var int */
    public $flags = 0;
    /** @var int */
    public $remainingLength;
    /** @var int */
    public $id;

    /**
     * @param string $response
     * @return static
     */
    abstract public static function instance($response = null);
}
