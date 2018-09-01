<?php
namespace Spiderman;

class ProcessPool
{
    protected $workers;

    protected $queue;

    private $_workerId;

    public function __construct($workers)
    {
    }

    /**
     * add event callback
     * @param string $event  event type, the available values are workerstart, workerstop or message
     * @param callable $callback  the event callback
     * when the event type is workerstart and worker stop, the callback signature is like function (ProcessPool $pool, $workerId)
     * when the event type is message, the callback signature is like function (message, ProcessPool $pool, $workerId)
     */
    public function on($event, $callback)
    {

    }

    public function send($message)
    {

    }

    public function start()
    {

    }

    public function __destruct()
    {
        //@TODO: 销毁共享内存
    }
}