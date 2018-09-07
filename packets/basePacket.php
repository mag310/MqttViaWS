<?php

namespace Intersvyaz\MqttViaWS\packet;

/**
 * Базовый класс для пакетов
 */
abstract class basePacket
{
    /**
     * @param string $response
     * @return static
     */
    abstract public static function instance($response = null);
}
