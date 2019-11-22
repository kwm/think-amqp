# Think AMQP
基于 ThinkPHP 6 的 RabbitMQ 消息队列 AMQP 操作类

## 安装
> 该操作类需要依赖 PHP 的 AMQP 扩展，可使用 `pcel install amqp` 安装

```
composer require kwm/think-amqp
```
安装完成后，请确认 `vendor/services.php` 中数组里有添加 `think\\amqp\\Service`。若没有添加，请手工运行：`php think service:discover`

## 入门

#### 配置

将 [示例配置文件](example/config/amqp.php) 复制到项目 `config` 目录下 

#### 生产者

```php
\think\Container::pull('exchange')
->set('kwm')
->publish('message');
```

若交换机不存在，默认会创建 `fanout` 交换机，需要你在管理界面绑定对应的队列，比如将 `kwm` 交换机和 `kwm.queue` 队列绑定到一起

#### 消费者

```php
$mq = \think\Container::pull('queue')
    ->set('kwm.queue');
$mq->setPrefetchCount(1); //一次只取一条消息
$mq->consume(function (\AMQPEnvelope $msg, \AMQPQueue $queue){
    //...
    if (处理成功) {
        $queue->ack($msg->getDeliveryTag());
    } else {
        $queue->nack($msg->getDeliveryTag()); // 不确认，下次会再推送该消息
    }
});
```

#### 消费者封装

```php
\think\amqp\Consumer::exec('kwm.queue', function (\AMQPEnvelope $msg, \AMQPQueue $queue, $deathCount){
    return true; //消费成功，返回其他都会认为是失败
})
```
封装特性：

- 支持设置最大次数的失败自动重试 
- 重试可自定义延时时间

#### 消费者命令行

##### 配置

1. 配置文件： `app/tasks.php`，内容请见 [这里](example/app/tasks.php)

2. 启动消费者：`php think consumer kwm`