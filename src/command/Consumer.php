<?php

namespace think\amqp\command;

use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\Container;
use think\helper\Arr;

class Consumer extends Command
{
    protected function configure()
    {
        $this->setName('consumer')
             ->setDescription('队列消费者')
             ->addArgument('name', Argument::REQUIRED, '任务列表中的名称');
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return null|int
     * @throws
     */
    protected function execute(Input $input, Output $output)
    {
        $name = $input->getArgument('name');

        /** @var App $app */
        $app  = Container::getInstance();

        if (!file_exists($file = $app->getAppPath() . 'tasks.php')) {
            $output->error('没有找到任务配置');

            return 1;
        }

        $task = Arr::get(include $file, 'list.' . $name);

        if (empty($task) || empty($task['queue'])) {
            $output->error('没有找到相关任务');

            return 2;
        }

        // 转换注入的参数
        $task['queueName'] = $task['queue'];

        while (true) {
            $app->invokeMethod('\think\amqp\Consumer::exec', $task);

            usleep(100000);
        }

        return 0;
    }
}