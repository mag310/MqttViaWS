<?php

namespace Intersvyaz\MqttViaWS\protocol\packet;

use Intersvyaz\MqttViaWS\protocol\Mqtt;

class mqttUnsubscribePacket extends mqttBasePacket
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
        $packet->type = Mqtt::PACKET_UNSUBSCRIBE;

        if (empty($response)) {
            return $packet;
        }

    }
}
