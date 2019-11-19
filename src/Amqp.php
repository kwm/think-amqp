<?php


namespace think\amqp;

use AMQPChannel;
use AMQPConnection;
use AMQPConnectionException;
use think\Config;

class Amqp
{
    /** @var AMQPConnection AMQP 服务器连接 */
    public static $connection;

    /** @var AMQPChannel 当前通道 */
    protected $channel;

    /** @var array 连接选项 */
    protected $config = [
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

    public function __construct(array $config)
    {
        //静态的连接信息，进程内只连接一次
        if (is_null(self::$connection) || !self::$connection->isConnected()) {
            $config           = array_merge($this->config, $config);
            self::$connection = new AMQPConnection($config);
            self::$connection->pconnect();
        }

        // 实例化时都建立新通道
        $this->channel = new AMQPChannel(self::$connection);
    }

    public static function __make(Config $config)
    {
        return new static($config->get('amqp'));
    }

    /**
     * 设置当前通道中，消费者每次从服务端获取消息的数量，一旦到达这个数量，消费者不在接收消息
     *
     * 由于 RabbitMQ **暂不支持** qos 方法中的 $size 参数设置为非 0，所以建议直接用此方法来设置服务质量
     * @param int $count 每次从服务端获取消息的数量
     * @throws AMQPConnectionException
     */
    public function setPrefetchCount($count)
    {
        $this->channel->setPrefetchCount($count);
    }

    /**
     * 设置一个处理 AMQP 服务器 basic.ack 和 basic.nac 方法的回调
     * @param callable|NULL $ack_callback
     * @param callable|NULL $nack_callback
     */
    public function setConfirmCallback(callable $ack_callback = null, callable $nack_callback = null)
    {
        $this->channel->setConfirmCallback($ack_callback, $nack_callback);
    }

    /**
     * 设置一个处理 AMQP 服务器 basic.return 方法的回调
     * @param callable|NULL $return_callback
     */
    public function setReturnCallback(callable $return_callback = null)
    {
        $this->channel->setReturnCallback($return_callback);
    }

    /**
     * 开启事务
     */
    public function startTrans()
    {
        try {
            return $this->channel->startTransaction();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        try {
            return $this->channel->commitTransaction();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 事务回滚
     */
    public function rollback()
    {
        try {
            return $this->channel->rollbackTransaction();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取当前通道
     */
    public function getChannel()
    {
        return $this->channel;
    }
}