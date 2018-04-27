<?php

namespace Sledgehammer\Orm;

use Iterator;
use Sledgehammer\Core\Base;

/**
 * Helper class for the RepositoryCollection, which converts data to the mode instances when needed.
 */
class RepositoryCollectionIterator extends Base implements Iterator
{
    /**
     * @var Iterator
     */
    private $iterator;

    /**
     * @var string|Repository
     */
    private $repository;

    /**
     * @var string
     */
    private $model;

    /**
     * @param Iterator          $iterator
     * @param string|Repository $repository
     * @param string            $model
     */
    public function __construct($iterator, $repository, $model)
    {
        $this->iterator = $iterator;
        $this->repository = $repository;
        $this->model = $model;
    }

    public function current()
    {
        $repo = Repository::instance($this->repository);

        return $repo->convert($this->model, $this->iterator->current());
    }

    public function key()
    {
        return $this->iterator->key();
    }

    public function next()
    {
        return $this->iterator->next();
    }

    public function rewind()
    {
        return $this->iterator->rewind();
    }

    public function valid()
    {
        return $this->iterator->valid();
    }
}
