<?php
require_once __DIR__ . '/../vendor/autoload.php';

$processPool = new \Spiderman\ProcessPool(4);
$processPool->on('workerstart', function ($pool, $workerId) {
    echo 'Worker ' . $workerId . ' is started' . PHP_EOL;
    $pool->send(rand(1, 10000));
});
$processPool->on('workerstop', function ($pool, $workerId) {
    echo 'Worker ' . $workerId . ' is shutdown' . PHP_EOL;
});
$processPool->on('message', function ($message, $pool, $workerId) {
    echo 'Receive message: ' . $message . PHP_EOL;
});
$processPool->send('test1');
$processPool->send('test2');
$processPool->send('test3');
$processPool->start();