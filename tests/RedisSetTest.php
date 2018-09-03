<?php
namespace Spiderman\Test;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use Spiderman\IpcStructure\Set\RedisSet;

class RedisSetTest extends TestCase
{
    protected $set;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $options = [
            'schema' => $GLOBALS['REDIS_SCHEMA'],
            'host' => $GLOBALS['REDIS_HOST'],
            'port' => $GLOBALS['REDIS_PORT'],
            'database' => $GLOBALS['REDIS_DATABASE'],
        ];

        if (!empty($GLOBALS['REDIS_PASSWORD'])) {
            $options['password'] = $GLOBALS['REDIS_PASSWORD'];
        }

        $client = new Client($options);
        $client->del('redis_set_test');

        $this->set = new RedisSet($client, 'redis_set_test');
    }

    public function testBasic()
    {
        $this->assertSame(0, $this->set->card());
        $this->assertSame(false, $this->set->has(1));
        $this->assertSame(true, $this->set->add(1, 2, 3));
        $this->assertSame(3, $this->set->card());
        $this->assertSame(true, $this->set->has(1));
        $this->assertEquals([1, 2, 3], $this->set->members());
        $this->assertSame(true, $this->set->remove(1, 2));
        $this->assertSame(1, $this->set->card());
        $this->assertSame(false, $this->set->has(1));
        $this->assertEquals([3], $this->set->members());
    }
}
