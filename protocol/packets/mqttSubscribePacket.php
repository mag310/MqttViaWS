<?php

namespace Intersvyaz\MqttViaWS\packet;


use Intersvyaz\MqttViaWS\protocol\Mqtt;

class mqttSubscribePacket extends mqttBasePacket
{
    /** @var int */
    public $flags = 0x02;

    /** @var array */
    public $topicFilters;
    /**
     * @param string $response
     * @return static
     */
    public static function instance($response = null)
    {
        $packet = new static();
        $packet->type = Mqtt::PACKET_SUBSCRIBE;

        if (empty($response)) {
            return $packet;
        }

    }
}
