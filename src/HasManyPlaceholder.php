<?php

namespace Sledgehammer\Orm;

use ArrayAccess;
use Countable;
use Exception;
use Iterator;
use IteratorAggregate;
use Sledgehammer\Core\Collection;
use Sledgehammer\Core\Base;
use Sledgehammer\Core\PropertyPath;
use stdClass;

/**
 * This Placeholder facilitates lazy loading of hasMany relations.
 * A HasManyPlaceholder object behaves like an Collection containing all related objects from the repository, but only retrieves the objects on-access or on-change.
 */
class HasManyPlaceholder extends Base implements ArrayAccess, IteratorAggregate, Countable
{
    /**
     * @var string|Collection Initialy a reference "repository/model/property", but will be replaced with the referenced Collection
     */
    private $__placeholder;

    /**
     * @var stdClass The instance this placeholder belongs to
     */
    private $__container;

    public function __construct($reference, $container)
    {
        $this->__placeholder = $reference;
        $this->__container = $container;
    }

    public function __call($method, $args)
    {
        $this->replacePlaceholder();

        return call_user_func_array([$this->__placeholder, $method], $args);
    }

    public function __clone()
    {
        throw new Exception('Cloning is not allowed for repository-bound objects');
    }

    // @todo: mimic array errors and behavior on propery access and method invocation
    // Array access
    public function offsetExists($offset): bool
    {
        $this->replacePlaceholder();

        return $this->__placeholder->offsetExists($offset);
    }

    public function offsetGet($offset): mixed
    {
        $this->replacePlaceholder();

        return $this->__placeholder->offsetGet($offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->replacePlaceholder();
        $this->__placeholder->offsetSet($offset, $value);
    }

    public function offsetUnset($offset): void
    {
        $this->replacePlaceholder();
        $this->__placeholder->offsetUnset($offset);
    }

    // IteratorAggregate
    public function getIterator(): Iterator
    {
        $this->replacePlaceholder();

        return $this->__placeholder->getIterator();
    }

    // Countable
    public function count(): int
    {
        $this->replacePlaceholder();

        return $this->__placeholder->count();
    }

    /**
     * Replace the placeholder and return the array.
     */
    private function replacePlaceholder()
    {
        if (is_string($this->__placeholder) === false) { // Is the __reference already replaced?
            // Multiple lookups are valid use-case.
            // The property (as placeholder) could be passed to another function as a value.
            return;
        }
        $parts = explode('/', $this->__placeholder);
        $repositoryId = array_shift($parts);
        $model = array_shift($parts);
        $property = implode('/', $parts);
        $data = PropertyPath::get($property, $this->__container);
        if ($data !== $this) {
            notice('This placeholder belongs to an other (cloned?) container');
            $this->__placeholder = $data;

            return;
        }
        $repo = Repository::instance($repositoryId);
        $this->__placeholder = $repo->resolveProperty($this->__container, $property, ['model' => $model]);
    }
}
