<?php

namespace think\amqp;

use think\Service as BaseService;

class Service extends BaseService
{
    public $bind = [
        'exchange' => Exchange::class,
        'queue'    => Queue::class,
    ];

    public function register()
    {
        //非 http 模式，注册命令
        if (!$this->app->exists('http')) {
            $this->commands([
                'consumer' => command\Consumer::class,
            ]);
        }
    }
}
