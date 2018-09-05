<?php
namespace Spiderman;

class ProcessPool
{
    /**
     * the max message length send to the pool
     * default is 2M
     * @const int
     */
    const MAX_MESSAGE_LENGTH = 10 << 11;

    /**
     * when the worker receive this message, it will exit running
     * @const string
     */
    const WORKER_STOP_MESSAGE = 'OsSvrr9iY7gulovhWQ7Af15fLjaquWCFAV3N269LwWAsmBaL3Rx5DRGK5nEThkp1';

    /**
     * the worker numbers
     * @var int
     */
    protected $workers;

    /**
     * the message queue
     * @var resource
     */
    protected $queue;

    /**
     * the capacity of the message queue
     * @var int
     */
    protected $maxQueueNum;

    /**
     * the mutex semaphore
     * @var resource
     */
    protected $mutexSemaphore;

    /**
     * because the sem_release() function can only release the semaphore acquired by the calling process, so we use the blocking message queue instead
     * the queue item count semaphore
     * @var resource
     */
    protected $queueCountSemaphoreMessageQueue;

    /**
     * because the sem_release() function can only release the semaphore acquired by the calling process, so we use the blocking message queue instead
     * the working workers count semaphore
     * @var resource
     */
    protected $workingWorkerCountSemaphoreMessageQueue;

    /**
     * the worker start callback
     * @var callable
     */
    protected $onWorkerStart = null;

    /**
     * the worker stop callback
     * @var callable
     */
    protected $onWorkerStop = null;

    /**
     * the message callback
     * @var callable
     */
    protected $onMessage = null;

    /**
     * the workers pid set
     * @var array
     */
    private $_workerPids = [];

    /**
     * ProcessPool constructor.
     * @param int $workers  the worker numbers
     * @param int $maxQueueNum  the max length of the message queue
     */
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
     * @return $this
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

        return $this;
    }

    /**
     * send message to the pool
     * @param mixed $message  when the message is a complicated type, it will be serialized automatically
     * @return bool
     */
    public function send($message)
    {
        sem_acquire($this->mutexSemaphore);
        $result = $this->isQueueFull() ? false : msg_send($this->queue, 1, $message, true, false);

        if ($result) {
            msg_send($this->queueCountSemaphoreMessageQueue, 1, 1, false);
        }
        sem_release($this->mutexSemaphore);

        return $result;
    }

    /**
     * start the pool, it will not return until the pool complete the jobs
     */
    public function start()
    {
        $this->forkWorkers();

        pcntl_signal(SIGCHLD, [$this, 'handleWorkerExit']);

        while (true) {
            $completed = false;
            sem_acquire($this->mutexSemaphore);
            if (!($hasWorkingWorker = msg_receive($this->workingWorkerCountSemaphoreMessageQueue, 1, $msgtype, 1, $message, false, MSG_IPC_NOWAIT)) && $this->isQueueEmpty()) {
                $completed = true;
            } else {
                if ($hasWorkingWorker) {
                    msg_send($this->workingWorkerCountSemaphoreMessageQueue, 1, 1, false);
                }
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

    /**
     * fork worker processes
     */
    protected function forkWorkers()
    {
        while (count($this->_workerPids) < $this->workers) {
            $this->forkOneWorker();
        }
    }

    /**
     * fork one worker process
     * @return bool|int  return the child process id on success, and false on failure
     */
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

    /**
     * stop all the workers and release the resources
     */
    protected function terminate()
    {
        $this->stopAllWorkers();

        msg_remove_queue($this->queue);
        msg_remove_queue($this->queueCountSemaphoreMessageQueue);
        msg_remove_queue($this->workingWorkerCountSemaphoreMessageQueue);
        sem_remove($this->mutexSemaphore);
    }

    /**
     * stop all the workers
     */
    protected function stopAllWorkers()
    {
        foreach ($this->_workerPids as $pid) {
            $this->send(static::WORKER_STOP_MESSAGE);
        }

        foreach ($this->_workerPids as $pid) {
            pcntl_wait($status);
        }
    }

    /**
     * the SIGCHILD handler
     */
    protected function handleWorkerExit()
    {
        $childPid = pcntl_wait($status);
        unset($this->_workerPids[$childPid]);
        $this->forkWorkers();
    }

    /**
     * get a message queue
     * @return resource
     */
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

    /**
     * work loop
     */
    protected function doWork()
    {
        if (!is_null($this->onWorkerStart)) {
            call_user_func($this->onWorkerStart, $this, posix_getpid());
        }

        while (true) {
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

    /**
     * get the current message queue length
     * @return int
     */
    protected function getCurrentQueueLength()
    {
        return msg_stat_queue($this->queue)['msg_qnum'];
    }

    /**
     * return whether the message queue is full
     * @return bool
     */
    protected function isQueueFull()
    {
        return $this->getCurrentQueueLength() >= $this->maxQueueNum;
    }

    /**
     * return whether the message queue is empty
     * @return bool
     */
    protected function isQueueEmpty()
    {
        return $this->getCurrentQueueLength() <= 0;
    }
}
