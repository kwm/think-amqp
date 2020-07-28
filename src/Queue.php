<?php


namespace think\amqp;

use AMQPEnvelope;
use AMQPQueue;
use AMQPQueueException;
use AMQPTimestamp;
use Exception;
use think\Container;

class Queue extends Amqp
{
    /**
     * 当前队列
     *
     * @var AMQPQueue
     */
    protected $queue;

    /**
     * 声明队列时返回的消息数量
     *
     * @var int
     */
    protected $messageCount;

    /**
     * 设置队列
     * @param string $name      队列名，最多255字节的一个utf-8字符串，为空时会自动生成
     *                          在同一个通道（channel）的后续的方法（method）中，我们可以使用空字符串来表示之前生成的队列名称
     * @param int    $flags     标识，可叠加使用，比如：AMQP_DURABLE | AMQP_EXCLUSIVE
     *                          <p>AMQP_DURABLE：    持久化，如果在创建新队列时设置，队列将被标记为持久。请 <b>注意</b>：持久队列不一定包含持久消息
     *                          <p>AMQP_PASSIVE：    被动，可用于检测服务器是否有该名称的队列
     *                          <p>AMQP_EXCLUSIVE：  独有，只被一个连接（connection）使用
     *                          <p>AMQP_AUTODELETE： 自动删除
     * @param array  $arguments 其他参数
     * @return $this
     * @throws
     */
    public function set($name, $flags = AMQP_PASSIVE, array $arguments = [])
    {
        $queue = new AMQPQueue($this->channel);

        // 设置队列名称
        $queue->setName($name);

        // 设置标记
        $queue->setFlags($flags);

        // 设置参数
        $queue->setArguments($arguments);

        /**
         * 声明队列
         * 队列在声明（declare）后才能被使用。
         * 如果一个队列尚不存在，声明一个队列会创建它。
         * 如果声明的队列已经存在，并且属性完全相同，那么此次声明不会对原有队列产生任何影响。
         * 如果声明中的属性与已存在队列的属性有差异，那么一个错误代码为406的通道级异常就会被抛出。
         */
        $this->messageCount = $queue->declareQueue();

        $this->queue = $queue;

        return $this;
    }

    /**
     * 将当前队列绑定到指定交换机
     * @param string $exchange_name 交换机名称
     * @param string $routing_key   路由键
     * @param array  $arguments     其他参数
     * @return $this
     * @throws
     */
    public function bindQueueToExchange($exchange_name, $routing_key = null, array $arguments = [])
    {
        //绑定到交换机
        $this->queue->bind($exchange_name, $routing_key, $arguments);

        return $this;
    }

    /**
     * 从队列中获取下一条消息，没有消息时也会立即返回
     * @param int $flags            标识
     *                              <p>AMQP_AUTOACK  自动确认
     *                              <p>AMQP_JUST_CONSUME
     * @return AMQPEnvelope
     * @throws
     */
    public function get($flags = AMQP_NOPARAM)
    {
        return $this->queue->get($flags);
    }

    /**
     * 订阅消息
     * @param callable $func        callable(AMQPEnvelope $message, AMQPQueue $queue)
     *                              <p>AMQPEnvelope $message   消息内容
     *                              <p>AMQPQueue    $queue     队列
     * @param int      $flags       标识
     *                              <p>AMQP_AUTOACK  自动确认
     *                              <p>AMQP_JUST_CONSUME
     * @param string   $consumerTag 消费者标识符，在当前通道中有效。两个客户端可以使用同一个标识符。为空时服务器将生成唯一标识
     *                              <p>可使用此标识符去取消订阅 (cancel)
     * @throws
     */
    public function consume(callable $func, int $flags = AMQP_NOPARAM, string $consumerTag = '')
    {
        try {
            $this->queue->consume($func, $flags, $consumerTag);
        } catch (AMQPQueueException $e) {
            // 消息者正常超时，不抛出异常
            if ($e->getMessage() != 'Consumer timeout exceed') {
                throw $e;
            }
        }

        $this->queue->cancel($consumerTag);
    }

    /**
     * 取消订阅，此方法用来清除消费者。
     * > 它不会影响到已经成功投递的消息，但是会使得服务器不再将新的消息投送给此消费者
     * @param string $consumerTag  消费者标识符，可用订阅时发送的标识符来取消订阅
     *                             <p>如果为空，那将取消最近的消息者
     * @throws
     */
    public function cancel($consumerTag = '')
    {
        $this->queue->cancel($consumerTag);
    }

    /**
     * 对一条或多条消息进行确认
     * @param int $delivery_tag 交付标识，可从消息里获得
     * @param int $flags        标识
     *                          <p>AMQP_NOPARAM：  默认值，确认指定消息
     *                          <p>AMQP_MULTIPLE： 一次性确认小于等于 delivery_tag 的所有消息，也就是在这之前的消息都会被确认
     * @throws
     */
    public function ack($delivery_tag, $flags = AMQP_NOPARAM)
    {
        $this->queue->ack($delivery_tag, $flags);
    }

    /**
     * 将消息转到 重试 队列
     *
     * 支持两种方式重试：
     * 1. 给队列设置 x-dead-letter-exchange 属性，$delay=0时，使用相关死信队列配置来重试
     *  > 正常队列(reject) -> x-dead-letter-exchange指定的重试交换机(绑定关系) -> 重试队列 (x-message-ttl过期) -> 重试队列
     *  x-dead-letter-exchanger 指定的正常交换机 -> 正常队列
     * 2. $delay 为非 0 时，手工指定延时时间，可按重试次数设置不同的延时
     *  >  正常队列(retry) -> *.retry 重试交换机(绑定关系) -> 重试队列 (x-message-ttl过期) -> 重试队列 x-dead-letter-exchanger 指定的正常交换机 ->
     *  正常队列
     *
     * *** 注意： 使用第 2 种重试方式时，重试队列的 x-message-ttl 必须设置为 多次调用的 $delay 的最大值 ***
     * @param AMQPEnvelope $message       消息对象
     * @param int          $delay         重试的延迟时间，单位：秒。如果为 0 ，则使用 死信队列 的设置
     * @param string       $exchange      重试交换机，为空时自动根据当前消息的交换机及配置后缀生成
     * @param string       $exchange_type 重试交换机类型，默认为 扇形交换机
     * @param string       $routing_key   新的路由键，为空时使用原消息路由键
     * @return bool
     * @throws
     */
    public function retry($message, $delay = 0, $exchange = '', $exchange_type = AMQP_EX_TYPE_FANOUT, $routing_key = '')
    {
        if ($delay === 0) {
            return $this->queue->reject($message->getDeliveryTag());
        }

        // 自定义延时时间，这里要求对应的 死信队列 TTL 设置为最长时间
        // 比如第1次延迟15秒，第2次延迟30秒，那 死信队列 TTL 应该设置为 30000 毫秒
        if (empty($exchange)) {
            $exchange = $message->getExchangeName() . $this->config['retry_exchange_suffix'];
        }

        $headers            = $message->getHeaders();
        $headers['x-death'] = $this->updateDeathHeader($message, 'rejected');

        Container::pull('exchange')
                 ->set($exchange, $exchange_type)
                 ->publish(
                     $message->getBody(),
                     $routing_key ?: $message->getRoutingKey(),
                     $message->getDeliveryMode(),
                     $headers,
                     [
                         'content_type'     => $message->getContentType(),
                         'content_encoding' => $message->getContentEncoding(),
                         'message_id'       => $message->getMessageId(),
                         'user_id'          => $message->getUserId(),
                         'app_id'           => $message->getAppId(),
                         'priority'         => $message->getPriority(),
                         'timestamp'        => $message->getTimestamp(),
                         'expiration'       => $delay * 1000,
                         'type'             => $message->getType(),
                         'reply_to'         => $message->getReplyTo(),
                     ]
                 );
        $this->queue->ack($message->getDeliveryTag());

        return true;
    }

    /**
     * 将消息转到 失败 队列
     * @param AMQPEnvelope $message       消息对象
     * @param string       $exchange      失败交换机，为空时自动根据当前消息的交换机及配置后缀生成
     * @param string       $exchange_type 失败交换机类型，默认为 扇形交换机
     * @param string       $routing_key   新的路由键，为空时使用原消息路由键
     * @throws
     */
    public function failed($message, $exchange = '', $exchange_type = AMQP_EX_TYPE_FANOUT, $routing_key = '')
    {
        if (empty($exchange)) {
            $exchange = $message->getExchangeName() . $this->config['failed_exchange_suffix'];
        }

        Container::pull('exchange')
                 ->set($exchange, $exchange_type)
                 ->publish(
                     $message->getBody(),
                     $routing_key ?: $message->getRoutingKey(),
                     $message->getDeliveryMode(),
                     $message->getHeaders(),
                     [
                         'content_type'     => $message->getContentType(),
                         'content_encoding' => $message->getContentEncoding(),
                         'message_id'       => $message->getMessageId(),
                         'user_id'          => $message->getUserId(),
                         'app_id'           => $message->getAppId(),
                         'priority'         => $message->getPriority(),
                         'timestamp'        => $message->getTimestamp(),
                         'expiration'       => $message->getExpiration(),
                         'type'             => $message->getType(),
                         'reply_to'         => $message->getReplyTo(),
                     ]
                 );

        $this->queue->ack($message->getDeliveryTag());
    }

    /**
     * 获取死信消息的流转次数/重试次数
     * @param AMQPEnvelope $message 消息对象
     * @param string       $reason  消息变成死信的原因，为空时获取最近的一条
     * @return int
     */
    public function getDeathCount(AMQPEnvelope $message, string $reason = '')
    {
        /** @var array|boolean $headers */
        $headers = $message->getHeader('x-death');
        if (empty($headers)) {
            return 0;
        } elseif (empty($reason)) {
            return $headers[0]['count'];
        }

        foreach ($headers as $header) {
            // 消息变成死信的原因相同，使用原数据（包括路由、时间等），次数 +1
            if ($header['reason'] == $reason) {
                return $header['count'];
            }
        }

        return 0;
    }

    /**
     * 更新死信消息头
     * @param AMQPEnvelope $message 消息对象
     * @param string       $reason  消息变成死信的原因
     * @return array
     * @throws
     */
    public function updateDeathHeader(AMQPEnvelope $message, string $reason)
    {
        /** @var array|boolean $headers */
        $headers     = $message->getHeader('x-death');
        $deathHeader = [
            'count'        => 1,
            'exchange'     => $message->getExchangeName(),
            'queue'        => $this->queue->getName(),
            'reason'       => $reason,
            'routing-keys' => [
                $message->getRoutingKey(),
            ],
            'time'         => new AMQPTimestamp(time()),
        ];

        if (empty($headers)) {
            return [$deathHeader];
        } else {
            foreach ($headers as $i => $header) {
                // 消息变成死信的原因相同，使用原数据（包括路由、时间等），次数 +1
                if ($header['reason'] == $reason) {
                    $deathHeader = $header;
                    $deathHeader['count']++;
                    unset($headers[$i]);
                    break;
                }
            }

            // 新消息头放在最前面
            array_unshift($headers, $deathHeader);

            return $headers;
        }
    }

    /**
     * 获取当前队列
     * @return AMQPQueue
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * 获取指定队列的消息数量
     * @return int
     */
    public function getMessageCount($name = '')
    {
        if ($name) {
            try {
                $queue = new AMQPQueue($this->channel);

                // 设置队列名称
                $queue->setName($name);

                // 设置标记
                $queue->setFlags(AMQP_PASSIVE);

                return $queue->declareQueue();
            } catch (Exception $e) {
                return 0; // 有异常返回 0
            }
        } else {
            return $this->messageCount;
        }
    }
}