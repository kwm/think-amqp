<?php

namespace think\amqp;

use AMQPEnvelope;
use AMQPQueue;
use Exception;
use think\Container;

class Consumer
{
    /**
     * 消费者的基础执行方法
     * @param string   $queueName    队列名称
     * @param callable $handle       业务回调方法
     * @param int      $maxRetry     最大重试次数
     * @param array    $retryOption  重试方法选项
     * @param array    $failedOption 失败方法选项
     */
    public static function exec($queueName, $handle, $maxRetry = 5, $retryOption = [], $failedOption = [])
    {
        try {
            /** @var Queue $mq */
            $mq = Container::pull('queue');
            $mq->set($queueName);
            $mq->setPrefetchCount(1);
            $mq->consume(function (AMQPEnvelope $msg, AMQPQueue $queue) use ($mq, $handle, $maxRetry, $retryOption, $failedOption) {
                $deathCount = $mq->getDeathCount($msg);
                try {
                    $res = call_user_func($handle, $msg, $queue, $deathCount);
                } catch (Exception $e) {
                    echo 'handle error: ', $e->getMessage(), '[', $e->getFile(), ':', $e->getLine(), ']' , PHP_EOL;
                    $res = false;
                }

                if ($res === true) {
                    $queue->ack($msg->getDeliveryTag());
                } elseif ($deathCount >= $maxRetry - 1) {
                    $failedOption['message'] = $msg;
                    Container::getInstance()->invokeMethod([$mq, 'failed'], $failedOption);
                } else {
                    $retryOption['message'] = $msg;
                    if (isset($retryOption['delay']) && is_callable($retryOption['delay'])) {
                        $retryOption['delay'] = call_user_func($retryOption['delay'], $deathCount);
                    }

                    Container::getInstance()->invokeMethod([$mq, 'retry'], $retryOption);
                }
            });
        } catch (Exception $e) {
            echo 'consumer exec error: ', $e->getMessage(), PHP_EOL;
            $mq->getQueue() && $mq->cancel();
        }
    }
}