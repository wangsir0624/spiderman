<?php
namespace Spiderman;

//@TODO: 添加日志打印功能，跟踪进程运行状态
use Predis\Client;
use Spiderman\IpcStructure\Set\RedisSet;
use GuzzleHttp\Client as GuzzleClient;
use Spiderman\IpcStructure\Set\SetInterface;

class Spiderman
{
    /**
     * Process pool
     * @var ProcessPool
     */
    protected $processPool;

    /**
     * the main settings
     * @var array
     */
    protected $settings;

    /**
     * the ipc set
     * @var SetInterface
     */
    protected $set;

    /**
     * the guzzle http client
     * @var GuzzleClient
     */
    protected $guzzleClient;

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
     * the before download callback
     * @var callable
     */
    protected $onBeforeDownload = null;

    /**
     * the after download callback
     * @var callable
     */
    protected $onAfterDownload = null;

    /**
     * the tick times
     * @var int
     */
    protected $ticks = 0;

    /**
     * the tick callback
     * @var callable
     */
    protected $onTick = null;

    /**
     * the guzzle options
     * @var array
     */
    protected $guzzleOptions = [];

    /**
     * the already processed link count
     * @var int
     */
    private $_alreadyProcessedCount = 0;

    /**
     * the current link level
     * @var int
     */
    private $_currentLinkLevel = 0;

    /**
     * Spiderman constructor.
     * @param array $settings
     */
    public function __construct($settings = [])
    {
        $this->settings = array_merge(static::getDefaultSettings(), $settings);
        $this->guzzleOptions = static::getDefaultGuzzleOptions();
        $this->initSet();
    }

    /**
     * start working
     * @param array|string $seeds
     */
    public function start($seeds)
    {
        $this->initProcessPool();

        !is_array($seeds) && $seeds = (array)$seeds;

        foreach ($seeds as $seed) {
            $this->push($seed);
        }

        $this->processPool->start();
    }

    /**
     * add event callback
     * @param string $event  event type, the available values are workerstart, workerstop, beforedownload, afterdownload
     * @param callable $callback
     * when the event type is workerstart, workerstop or beforedownload, the callback function signature is like function (Spiderman $spiderman, $workerId)
     * when the event type is afterdownload, the callback function signature is like function (Response $response, Spiderman $spiderman, $workerId)
     * @return $this;
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
            case 'beforedownload':
                $this->onBeforeDownload = $callback;
                break;
            case 'afterdownload':
                $this->onAfterDownload = $callback;
                break;
            default:
        }

        return $this;
    }

    /**
     * set the tick callback
     * @param int $times
     * @param callable $callback
     * whenever the worker processed $times tasks, it will call the tick callback handler
     * @return $this
     */
    public function tick($times, $callback)
    {
        if ($times > 0) {
            $this->onTick = $callback;
        }

        return $this;
    }

    /**
     * push the link to the process pool
     * @param string $link
     * @return bool
     */
    public function push($link)
    {
        $nextLevel = $this->_currentLinkLevel + 1;

        if ($nextLevel > $this->settings['max_level']) {
            return false;
        }

        $this->processPool->send(json_encode([$link, $nextLevel]));

        return true;
    }

    /**
     * set the guzzle options
     * @param array $options
     * @return $this
     */
    public function setGuzzleOptions($options)
    {
        $this->guzzleOptions = array_merge($options, [
            'http_errors' => false,
        ]);

        return $this;
    }

    /**
     * get the default main settings
     * @return array
     */
    protected static function getDefaultSettings()
    {
        return [
            'worker_num' => 4,
            'set_type' => 'redis',
            'redis_set_schema' => 'tcp',
            'redis_set_host' => '127.0.0.1',
            'redis_set_port' => 6379,
            'redis_set_database' =>0,
            'redis_set_password' => null,
            'redis_set_name' => 'spiderman_set_' . time() .  rand(100000, 999999),
            'max_level' => 10,
            'max_queue_num' => 10000,
        ];
    }

    /**
     * get the default guzzle options
     * @return array
     */
    protected static function getDefaultGuzzleOptions()
    {
        return [
            'http_errors' => false,
        ];
    }

    /**
     * the process pool worker start callback
     * @param ProcessPool $pool
     * @param $workerId
     */
    public function doWorkerStart(ProcessPool $pool, $workerId)
    {
        $this->initGuzzleClient();

        if (!is_null($this->onWorkerStart)) {
            call_user_func($this->onWorkerStart, $this, $workerId);
        }
    }

    /**
     * the process pool worker stop callback
     * @param ProcessPool $pool
     * @param $workerId
     */
    public function doWorkerStop(ProcessPool $pool, $workerId)
    {
        if (!is_null($this->onWorkerStop)) {
            call_user_func($this->onWorkerStop, $this, $workerId);
        }
    }

    /**
     * the process pool message callback
     * @param mixed $message
     * @param ProcessPool $pool
     * @param $workerId
     */
    public function doMessage($message, ProcessPool $pool, $workerId)
    {
        list($link, $this->_currentLinkLevel) = json_decode($message);

        if ($this->set->has($link)) {
            return;
        }

        if ($this->ticks > 0 && $this->_alreadyProcessedCount > 0 && $this->_alreadyProcessedCount % $this->ticks == 0) {
            if (!is_null($this->onTick)) {
                call_user_func($this->onTick, $this, $workerId);
            }
        }

        if (!is_null($this->onBeforeDownload)) {
            call_user_func($this->onBeforeDownload, $this, $workerId);
        }

        $response = $this->guzzleClient->get($link, $this->guzzleOptions);

        if (!is_null($this->onAfterDownload)) {
            call_user_func($this->onAfterDownload, $response, $this, $workerId);
        }

        $this->_alreadyProcessedCount++;
        $this->set->add($link);
    }

    /**
     * init the ipc set
     * @return $this
     */
    protected function initSet()
    {
        switch ($this->settings['set_type']) {
            case 'redis':
                $options = [
                    'schema' => $this->settings['redis_set_schema'],
                    'host' => $this->settings['redis_set_host'],
                    'port' => $this->settings['redis_set_port'],
                    'database' => $this->settings['redis_set_database'],
                ];

                if (!empty($this->settings['redis_set_password'])) {
                    $options['password'] = $this->settings['redis_set_password'];
                }

                $this->set = new RedisSet(new Client($options), $this->settings['redis_set_name']);
                break;
            default:
                throw new \RuntimeException('Unsupported set type');
        }

        return $this;
    }

    /**
     * init the guzzle client
     * @return $this
     */
    protected function initGuzzleClient()
    {
        $this->guzzleClient = new GuzzleClient();

        return $this;
    }

    /**
     * init the process pool
     * @return $this
     */
    protected function initProcessPool()
    {
        $this->processPool = new ProcessPool($this->settings['worker_num'], $this->settings['max_queue_num']);

        $this->processPool->on('workerstart', [$this, 'doWorkerStart']);
        $this->processPool->on('workerstop', [$this, 'doWorkerStop']);
        $this->processPool->on('message', [$this, 'doMessage']);

        return $this;
    }
}
