<?php
namespace Spiderman;

class ProcessPool
{
    const MAX_MESSAGE_LENGTH = 10 << 11;

    protected $workers;

    protected $queue;

    protected $maxQueueNum;

    protected $pipe;

    protected $mutexSemaphore;

    protected $onWorkerStart = null;

    protected $onWorkerStop = null;

    protected $onMessage = null;

    private $_workerPids = [];

    public function __construct($workers, $maxQueueNum = 10000)
    {
        $this->workers = $workers;

        //initial the message queue
        $this->initMessageQueue($maxQueueNum);

        $this->pipe = stream_socket_server('unix:///var/tmp/spiderman.sock', $errno, $errstr);
        if ($errno) {
            throw new \RuntimeException($errstr);
        }

        $this->mutexSemaphore = sem_get(mt_rand(1, PHP_INT_MAX));
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
        switch ($event) {
            case 'workerstart':
                $this->onWorkerStart = $callback;
                break;
            case 'workerstop':
                $this->onWorkerStop = $callback;
                break;
            case 'message':
                $this->onMessage = $callback;
                break;
            default:
        }
    }

    public function send($message)
    {
        sem_acquire($this->mutexSemaphore);
        $result = $this->isQueueFull() ? false : msg_send($this->queue, 1, $message, true, false);
        var_dump($result);
        if($result) {
            var_dump(fwrite($this->pipe, 1));
        }
        sem_release($this->mutexSemaphore);

        return $result;
    }

    public function start()
    {
        $this->forkWorkers();

        pcntl_signal(SIGCHLD, [$this, 'handleWorkerExit']);

        while (true) {
            pcntl_signal_dispatch();
            sleep(1);
        }
    }

    protected function forkWorkers()
    {
        while (count($this->_workerPids) < $this->workers) {
            $this->forkOneWorker();
        }
    }

    protected function forkOneWorker()
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            $this->_workerPids[$pid] = true;
            return $pid;
        } elseif ($pid == 0) {
            $this->doWork();
            exit;
        } else {
            return false;
        }
    }


    protected function handleWorkerExit()
    {
        $childPid = pcntl_wait($status);
        unset($this->_workerPids[$childPid]);
        $this->forkWorkers();
    }

    protected function initMessageQueue($maxQueueNum)
    {
        while (true) {
            $key = mt_rand(1, PHP_INT_MAX);
            if (msg_queue_exists($key)) {
                continue;
            }

            $this->queue = msg_get_queue($key);
            $this->maxQueueNum = $maxQueueNum;
            break;
        }
    }

    protected function doWork()
    {
        if (!is_null($this->onWorkerStart)) {
            call_user_func($this->onWorkerStart, $this, posix_getpid());
        }

        while (true) {
            var_dump(fread($this->pipe, 1));

            sem_acquire($this->mutexSemaphore);
            $result = msg_receive($this->queue, 1, $msgtype, static::MAX_MESSAGE_LENGTH, $message, true, MSG_IPC_NOWAIT);
            sem_acquire($this->mutexSemaphore);
            if (!$result) {
                continue;
            }

            if (!is_null($this->onMessage)) {
                call_user_func($this->onMessage, $message, $this, posix_getpid());
            }
        }

        if (!is_null($this->onWorkerStop)) {
            call_user_func($this->onWorkerStop, $this, posix_getpid());
        }
    }

    protected function getCurrentQueueLength()
    {
        return msg_stat_queue($this->queue)['msg_qnum'];
    }

    protected function isQueueFull()
    {
        return $this->getCurrentQueueLength() >= $this->maxQueueNum;
    }

    protected function isQueueEmpty()
    {
        return $this->getCurrentQueueLength() <= 0;
    }

    public function __destruct()
    {
        //@TODO: 销毁共享内存，共享内存不能写在析构函数中，而应该写在进程池关闭的时候
    }
}