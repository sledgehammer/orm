<?php

/**
 * BelongsToPlaceholder
 */

namespace Sledgehammer;

/**
 * This Placeholder facilitates lazy loading of belongsTo relations.
 * A BelongsToPlaceholder object behaves like the object from the repository, but only retrieves the real object on-access or on-change.
 *
 * @todo Implement ArrayAcces and Iterator interfaces. Which the target property might support.
 *
 * @package ORM
 */
class BelongsToPlaceholder extends Object {

    /**
     * @var array
     */
    private $__fields;

    /**
     * @var string|stdClass Initialy a reference "repository/model/id", but will be replaced with the referenced Object
     */
    private $__placeholder;

    /**
     * @var stdClass  The instance this placeholder belongs to
     */
    private $__container;

    function __construct($reference, $container, $fields = array()) {
        $this->__placeholder = $reference;
        $this->__container = $container;
        $this->__fields = $fields;
    }

    function __get($property) {
        if (array_key_exists($property, $this->__fields)) {
            return $this->__fields[$property]; // ->id
        }
        $this->__replacePlaceholder();
        return $this->__placeholder->$property;
    }

    function __set($property, $value) {
        $this->__replacePlaceholder();
        $this->__placeholder->$property = $value;
    }

    function __call($method, $arguments) {
        $this->__replacePlaceholder();
        return call_user_func_array(array($this->__placeholder, $method), $arguments);
    }

    function __clone() {
        throw new \Exception('Cloning is not allowed for repository-bound objects');
    }

    /**
     * Replace the placeholder with the referenced object
     *
     * @return void
     */
    private function __replacePlaceholder() {
        if (is_string($this->__placeholder) === false) { // Is the placeholder already replaced?
            // Multiple lookups are valid use-case.
            // The property (as placeholder) could be passed to another function as a value.
            return;
        }
        $parts = explode('/', $this->__placeholder);
        $repositoryId = array_shift($parts);
        $model = array_shift($parts);
        $property = implode('/', $parts);
        $self = PropertyPath::get($property, $this->__container);
        if ($self !== $this) {
            notice('This placeholder belongs to an other object', 'Did you clone the object?');
            $this->__placeholder = $self;
            return;
        }
        $repo = getRepository($repositoryId);
        $this->__placeholder = $repo->resolveProperty($this->__container, $property, array('model' => $model));
    }

}

?>