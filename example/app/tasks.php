<?php
// 命令行任务配置示例，请将此文件复制到 app/tasks.php

return [
    'list' => [
        // 任务名
        'kwm' => [
            // 列表名称
            'queue'        => 'kwm.queue',
            // 处理方法，callable 支持的所有方式
            'handle'       => function (AMQPEnvelope $message, AMQPQueue $queue, $deathCount) {
                var_dump($message->getBody());

                return true;
            },
            // 最大重试次数
            'maxRetry'     => 5,
            // 重试方法选项
            'retryOption'  => [
                // 重试的延时时间，单位：秒。可使用回调
                'delay'         => 10,
                // 重试交换机，默认为 `当前消息源交换机.retry`
                'exchange'      => '',
                // 重试交换机类型
                'exchange_type' => AMQP_EX_TYPE_FANOUT,
                // 重试消息的路由键，为空时使用消息的原路由键
                'routing_key'   => '',
            ],
            // 失败方法选项
            'failedOption' => [
                // 失败交换机，默认为 `当前消息源交换机.failed`
                'exchange'      => '',
                // 失败交换机类型
                'exchange_type' => AMQP_EX_TYPE_FANOUT,
                // 失败消息的路由键，为空时使用消息的原路由键
                'routing_key'   => '',
            ],
        ],
    ],
];
