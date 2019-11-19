<?php


namespace think\amqp;

use AMQPExchange;
use AMQPExchangeException;

class Exchange extends Amqp
{
    /**
     * 当前交换机
     *
     * @var AMQPExchange
     */
    protected $exchange;

    /**
     * 设置延时消息交换机，基本参数和 exchange 方法相同
     * @param string  $name      交换机名称
     * @param string  $type      交换机类型，AMQP_EX_TYPE_DIRECT：直连交换机、AMQP_EX_TYPE_FANOUT：扇形交换机、AMQP_EX_TYPE_TOPIC：主题交换机、AMQP_EX_TYPE_HEADERS：头交换机
     * @param integer $flags     标记，AMQP_DURABLE：持久，AMQP_PASSIVE：被动
     * @param array   $arguments 其他参数
     * @return $this
     */
    public function delay($name = '', $type = AMQP_EX_TYPE_FANOUT, $flags = AMQP_DURABLE, $arguments = [])
    {
        //设置延时消息对应的基本交换机类型
        $arguments['x-delayed-type'] = $type;

        return $this->set($name, 'x-delayed-message', $flags, $arguments);
    }

    /**
     * 设置交换机
     * @param string  $name       交换机名称
     * @param string  $type       交换机类型
     *                            <p> 默认交换机：名称为空的直连交换机，每个新建队列（queue）都会自动绑定到默认交换机上，绑定的路由键（routing key）名称与队列名称相同。
     *                            <p>AMQP_EX_TYPE_DIRECT：  直连交换机，将消息发送到 路由键 同名的队列上 -- 只全等判断路由键，不关心 绑定 关系（一对一）
     *                            <p>AMQP_EX_TYPE_FANOUT：  扇形交换机，将消息发送到 与当前交换机有绑定关系 的队列上 -- 只判断 绑定 关系，不关心 路由键（一对多）
     *                            <p>AMQP_EX_TYPE_TOPIC：   主题交换机，将消息发送到 消息的 路由键 与 队列绑定的 路由键 相匹配的队列上 -- 在 绑定 关系基础上再匹配
     *                            路由键 （多对多）
     *                            <p>AMQP_EX_TYPE_HEADERS： 头交换机
     * @param integer $flags      标记
     *                            <p>AMQP_DURABLE：         持久
     *                            <p>AMQP_PASSIVE：         被动，交换机名不存在时，不会自动创建
     * @param array   $arguments  其他参数
     * @return $this
     * @throws
     */
    public function set($name = '', $type = AMQP_EX_TYPE_FANOUT, $flags = AMQP_DURABLE, $arguments = [])
    {
        $exchange = new AMQPExchange($this->channel);

        //设置交换机名称
        $exchange->setName($name);

        //设置交换机类型
        $exchange->setType($type);

        //设置交换机标记
        $exchange->setFlags($flags);

        //其他参数
        $exchange->setArguments($arguments);

        //如果有设置交换机名称，那声明交换机
        if ($name) {
            $exchange->declareExchange();
        }

        $this->exchange = $exchange;

        return $this;
    }

    /**
     * 发送延时消息
     * @param int    $delayTime     延迟时间，单位：秒
     * @param string $message       消息内容
     * @param string $routing_key   路由键
     * @param int    $delivery_mode 消息的持久性，1：非持久，2：持久
     * @param array  $headers       消息头信息，可用于头交换机 或 设置 延时消息标记
     * @param array  $attributes    消息属性
     * @param int    $flags         消息标记
     * @return bool
     * @throws
     * @see    publish
     */
    public function publishDelay(int $delayTime, $message, $routing_key = '', int $delivery_mode = 2,
                                 array $headers = [], array $attributes = [], int $flags = AMQP_MANDATORY)
    {
        if (!is_object($this->exchange) || $this->exchange->getType() != 'x-delayed-message') {
            throw new AMQPExchangeException('非延时交换机，不能发送延时消息');
        }

        // 延迟时间设置为秒
        $headers['x-delay'] = $delayTime * 1000;

        return $this->publish($message, $routing_key, $delivery_mode, $headers, $attributes, $flags);
    }

    /**
     * 发送消息
     * @param string $message       消息内容
     * @param string $routing_key   消息的路由键
     * @param int    $delivery_mode 消息的持久性，1：非持久，2：持久
     * @param array  $headers       消息头信息，可用于头交换机 或 设置 延时消息标记
     * @param array  $attributes    消息属性，以下为有效的属性，其他无效属性将被忽略
     *                              <P>content_type:     内容类型，默认为：text/plain
     *                              <P>content_encoding  内容编码
     *                              <P>message_id        应用程序设定的消息ID
     *                              <P>user_id           创建用户ID
     *                              <P>app_id            创建应用ID
     *                              <P>priority          消息优先级，0 ~ 255
     *                              <P>timestamp         消息时间戳
     *                              <P>expiration        消息过期规范
     *                              <P>type              消息类型
     *                              <P>reply_to          回复地址
     * @param int    $flags         消息标记
     *                              <p>AMQP_MANDATORY: 消息无法匹配队列时，会返还给生产者
     *                              <p>AMQP_IMMEDIATE: 消息匹配的队列没有消费者时，消息不会放到队列中。所有匹配队列都没有消费者时，会返还给生产者
     * @return bool
     * @throws
     */
    public function publish($message, $routing_key = '', int $delivery_mode = 2,
                            array $headers = [], array $attributes = [], $flags = AMQP_MANDATORY)
    {
        if (!is_object($this->exchange)) {
            throw new AMQPExchangeException('未设置发送消息的交换机');
        }

        $attributes['delivery_mode'] = $delivery_mode;
        $attributes['headers']       = $headers;

        return $this->exchange->publish($message, $routing_key, $flags, $attributes);
    }

    /**
     * 获取当前交换机
     *
     * @author YangQi
     * @return AMQPExchange
     */
    public function getExchange()
    {
        return $this->exchange;
    }
}