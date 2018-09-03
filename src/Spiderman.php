<?php
namespace Spiderman;

//@TODO: 添加日志打印功能，跟踪进程运行状态
class Spiderman
{
    protected $processPool;

    protected $settings;

    protected $client;

    protected $_alreadyProcessedSet;

    private $_alreadyProcessedCount = 0;

    private $_currentLinkLevel = 0;

    public function start($seeds)
    {
        //@TODO: 初始化进程池回调函数，因为多进程会复制一份内存，所以子进程也可以访问到Spiderman和ProcessPool对象

        //@TODO: 开启进程池，并将seed放进进程池
    }

    /**
     * add event callback
     * @param string $event  event type, the available values are workerstart, workerstop, beforedownload, afterdownload
     * @param callable $callback
     * when the event type is workerstart, workerstop or beforedownload, the callback function signature is like function (Spiderman $spiderman, ProcessPool $pool, $workerId)
     * when the event type is afterdownload, the callback function signature is like function (Response $response, Spiderman $spiderman, ProcessPool $pool, $workerId)
     */
    public function on($event, $callback)
    {
    }

    /**
     * set the tick callback
     * @param int $times
     * @param callable $callback
     * whenever the worker processed $times tasks, it will call the tick callback handler
     */
    public function tick($times, $callback)
    {
    }

    /**
     * push the link to the process pool
     * @param string $link
     */
    public function push($link)
    {
        //@TODO: 将连接推入到进程池处理，连接的深度值为$_currentLinkLevel+1

        //@TODO: 如果link的深度超过了设置的最大深度，那么直接返回，推入无效
    }

    public function setGuzzleOptions($options)
    {
        //@TODO: 设置guzzle client参数，提供代理，HTTP验证等功能
    }

    protected static function getDefaultSettings()
    {
        return [

        ];
    }

    protected function doWorkerStart(ProcessPool $pool, $workerId)
    {
    }

    protected function doWorkerStop(ProcessPool $pool, $workerId)
    {
    }

    protected function doMessage($message, ProcessPool $pool, $workerId)
    {
        //@TODO: 检查这个链接是否已经处理过，如果处理过，直接跳过

        //@TODO: 检查已处理的个数是否满足tick条件，如果可以，出发tick回调

        //@TODO: 从message中解析出link和level信息，并将Level赋值到当前level $_currentLinkLevel

        //@TODO: 如果注册了beforedownload回调，先调用回调

        //@TODO: 使用guzzle client 抓取页面

        //@TODO: 如果注册了afterdownload回调，调用回调

        //@TODO: $_alreadyProcessedCount++
    }
}
