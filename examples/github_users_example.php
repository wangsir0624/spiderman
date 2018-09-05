<?php
require_once __DIR__ . '/../vendor/autoload.php';

if ($argc < 2) {
    echo 'Please input the start user: ';
    $argv[1] = trim(fgets(STDIN));
}

$spiderman = new \Spiderman\Spiderman([
    'max_level' => 3,
]);

$logfile = fopen('/tmp/github_users_result.csv', 'w');
fputcsv($logfile, [
    'username',
    'repositories',
    'stars',
    'followers',
    'followings',
]);
$sem = sem_get(ftok(__FILE__, 's'));

$spiderman->on('workerstart', function ($spiderman, $workerId) {
    echo "start worker $workerId" . PHP_EOL;
});

$spiderman->on('workerstop', function ($spiderman, $workerId) {
    echo "stop worker $workerId" . PHP_EOL;
});

$spiderman->on('beforedownload', function ($link, $spiderman, $workerId) {
    echo "start fetching $link" . PHP_EOL;
});

$spiderman->on('afterdownload', function ($link, $response, $spiderman, $workerId) use ($logfile, $sem) {
    if ($response->getStatusCode() == 200) {
        preg_match('/^https\:\/\/github.com\/(\w+)\?tab=followers$/', $link, $matches);

        //parse the html
        $html = new \DiDom\Document((string)$response->getBody());
        $spans = $html->find('.user-profile-nav')[0]->find('span.Counter');

        sem_acquire($sem);
        fputcsv($logfile, [
            $matches[1],
            trim($spans[0]->text()),
            trim($spans[1]->text()),
            trim($spans[2]->text()),
            trim($spans[3]->text()),
        ]);
        sem_release($sem);

        $followerSpans = $html->find('.link-gray');
        foreach ($followerSpans as $span) {
            $spiderman->push(sprintf('https://github.com/%s?tab=followers', $span->text()));
        }

        echo "finish fetching $link" . PHP_EOL;
    } else {
        echo 'Status Code: ' . $response->getStatusCode() . PHP_EOL;
        echo "fetch $link failed" . PHP_EOL;
    }
});

$spiderman->start("https://github.com/$argv[1]?tab=followers");

fclose($logfile);
sem_remove($sem);
