<?php

namespace think\amqp;

class Service
{
    public $bind = [
        'exchange' => Exchange::class,
        'queue'    => Queue::class,
    ];
}
