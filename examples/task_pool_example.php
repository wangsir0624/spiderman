<?php
require_once __DIR__ . '/../vendor/autoload.php';

class Task
{
    protected $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function run()
    {
        echo 'start doing task ' . $this->id . PHP_EOL;
        echo 'doing task ' . $this->id . PHP_EOL;
        echo 'finish doing task ' . $this->id . PHP_EOL;
    }
}

$processPool = new \Spiderman\ProcessPool(4);

$processPool->on('workerstart', function ($pool, $workerId) {
    echo 'Worker ' . $workerId . ' is started' . PHP_EOL;
});
$processPool->on('workerstop', function ($pool, $workerId) {
    echo 'Worker ' . $workerId . ' is shutdown' . PHP_EOL;
});
$processPool->on('message', function ($message, $pool, $workerId) {
    if ($message instanceof Task) {
        $message->run();
    }
});

for ($i = 1; $i <= 100; $i++) {
    $processPool->send(new Task($i));
}

$processPool->start();
