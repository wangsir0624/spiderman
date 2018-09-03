<?php
namespace Spiderman\IpcStructure\Set;

use Predis\Client;

class RedisSet implements SetInterface
{
    /**
     * the predis client
     * @var Client
     */
    protected $client;

    /**
     * the set key name
     * @var string
     */
    protected $name;

    /**
     * RedisSet constructor.
     * @param Client $client
     * @param string $name
     */
    public function __construct(Client $client, $name)
    {
        $this->client = $client;
        $this->name = $name;
    }

    /**
     * add members to the set
     * @param array ...$items
     * @return bool
     */
    public function add(...$items)
    {
        return (bool)$this->client->sadd($this->name, $items);
    }

    /**
     * remove members from the set
     * @param array ...$items
     * @return bool
     */
    public function remove(...$items)
    {
        return (bool)$this->client->srem($this->name, $items);
    }

    /**
     * does the item exist in the set
     * @param string $item
     * @return bool
     */
    public function has($item)
    {
        return (bool)$this->client->sismember($this->name, $item);
    }

    /**
     * return the member count in the set
     * @return int
     */
    public function card()
    {
        return $this->client->scard($this->name);
    }

    /**
     * return all the set members
     * @return array
     */
    public function members()
    {
        return $this->client->smembers($this->name);
    }
}
