<?php
namespace Spiderman;

class ProcessPool
{
    const MAX_MESSAGE_LENGTH = 10 << 11;

    const WORKER_STOP_MESSAGE = 'OsSvrr9iY7gulovhWQ7Af15fLjaquWCFAV3N269LwWAsmBaL3Rx5DRGK5nEThkp1';

    protected $workers;

    protected $queue;

    protected $maxQueueNum;

    protected $mutexSemaphore;

    protected $queueCountSemaphoreMessageQueue;

    protected $workingWorkerCountSemaphoreMessageQueue;

    protected $onWorkerStart = null;

    protected $onWorkerStop = null;

    protected $onMessage = null;

    private $_workerPids = [];

    private $_running = false;

    public function __construct($workers, $maxQueueNum = 10000)
    {
        $this->workers = $workers;

        //initial the message queue
        $this->queue = $this->getMessageQueue();
        $this->maxQueueNum = $maxQueueNum;
        $this->mutexSemaphore = sem_get(mt_rand(1, PHP_INT_MAX));
        $this->queueCountSemaphoreMessageQueue = $this->getMessageQueue();

        $this->workingWorkerCountSemaphoreMessageQueue = $this->getMessageQueue();
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

    public function send($message, $lock = true)
    {
        $lock && sem_acquire($this->mutexSemaphore);
        $result = $this->isQueueFull() ? false : msg_send($this->queue, 1, $message, true, false);

        if ($result) {
            msg_send($this->queueCountSemaphoreMessageQueue, 1, 1, false);
        }
        $lock && sem_release($this->mutexSemaphore);

        return $result;
    }

    public function start()
    {
        $this->forkWorkers();

        pcntl_signal(SIGCHLD, [$this, 'handleWorkerExit']);

        while (true) {
            $completed = false;
            sem_acquire($this->mutexSemaphore);
            if ($this->isQueueEmpty() && !msg_receive($this->workingWorkerCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false, MSG_IPC_NOWAIT)) {
                $completed = true;
            } else {
                msg_send($this->workingWorkerCountSemaphoreMessageQueue, 1, 1, false);
            }
            sem_release($this->mutexSemaphore);

            if ($completed) {
                $this->terminate();
                return;
            }

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

    protected function terminate()
    {
        $this->stopAllWorkers();

        msg_remove_queue($this->queue);
        msg_remove_queue($this->queueCountSemaphoreMessageQueue);
        msg_remove_queue($this->workingWorkerCountSemaphoreMessageQueue);
        sem_remove($this->mutexSemaphore);
    }

    protected function stopAllWorkers()
    {
        foreach ($this->_workerPids as $pid) {
            $this->send(static::WORKER_STOP_MESSAGE, false);
        }

        foreach($this->_workerPids as $pid) {
            pcntl_wait($status);
        }
    }

    protected function handleWorkerExit()
    {
        $childPid = pcntl_wait($status);
        unset($this->_workerPids[$childPid]);
        //$this->forkWorkers();
    }

    protected function getMessageQueue()
    {
        while (true) {
            $key = mt_rand(1, PHP_INT_MAX);
            if (msg_queue_exists($key)) {
                continue;
            }

            return msg_get_queue($key);
        }
    }

    protected function doWork()
    {
        if (!is_null($this->onWorkerStart)) {
            call_user_func($this->onWorkerStart, $this, posix_getpid());
        }

        while ($this->_running) {
            msg_receive($this->queueCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false);

            sem_acquire($this->mutexSemaphore);
            $result = msg_receive($this->queue, 1, $msgtype, static::MAX_MESSAGE_LENGTH, $message, true, MSG_IPC_NOWAIT);
            msg_send($this->workingWorkerCountSemaphoreMessageQueue, 1, 1, false);
            sem_release($this->mutexSemaphore);

            if (!$result) {
                sem_acquire($this->mutexSemaphore);
                msg_receive($this->workingWorkerCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false);
                sem_release($this->mutexSemaphore);

                continue;
            }

            if ($message == static::WORKER_STOP_MESSAGE) {
                sem_acquire($this->mutexSemaphore);
                msg_receive($this->workingWorkerCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false);
                sem_release($this->mutexSemaphore);

                break;
            }

            try {
                if (!is_null($this->onMessage)) {
                    call_user_func($this->onMessage, $message, $this, posix_getpid());
                }
            } finally {
                sem_acquire($this->mutexSemaphore);
                msg_receive($this->workingWorkerCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false);
                sem_release($this->mutexSemaphore);
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
}