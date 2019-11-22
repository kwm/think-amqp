<?php
// 示例配置文件
return [
    'host'                   => '127.0.0.1',
    'port'                   => 5672,
    'login'                  => 'guest',
    'password'               => 'guest',
    'vhost'                  => '/',
    'read_timeout'           => 30, // 读取超时时间，该设置会影响消费者订阅的阻塞时间，设置为 0 时，不会超时
    'write_timeout'          => 30,
    'connect_timeout'        => 5,
    'retry_exchange_suffix'  => '.retry', // 重试消息交换机的后缀
    'failed_exchange_suffix' => '.failed', // 失败消息交换机的后缀
];