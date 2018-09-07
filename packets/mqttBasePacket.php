<?php

namespace Intersvyaz\MqttViaWS\packet;

/**
 * Класс для Mqtt пакетов
 * Class mqttBasePacket
 *
 * @package Intersvyaz\MqttViaWS\packet
 */
abstract class mqttBasePacket extends basePacket
{
    /** @var int */
    public $type;
    /** @var int */
    public $flags = 0;
    /** @var int */
    public $remainingLength;
    /** @var int */
    public $id;
}
