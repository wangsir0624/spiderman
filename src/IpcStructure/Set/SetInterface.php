<?php
namespace Spiderman\IpcStructure\Set;

interface SetInterface
{
    /**
     * add members to the set
     * @param array ...$items
     * @return bool
     */
    public function add(...$items);

    /**
     * remove members from the set
     * @param array ...$items
     * @return bool
     */
    public function remove(...$items);

    /**
     * does the item exist in the set
     * @param string $item
     * @return bool
     */
    public function has($item);

    /**
     * return the member count in the set
     * @return int
     */
    public function card();

    /**
     * return all the set members
     * @return array
     */
    public function members();
}
