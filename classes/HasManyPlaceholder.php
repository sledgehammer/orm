<?php
/**
 * HasManyPlaceholder
 */
namespace Sledgehammer;
/**
 * This Placeholder facilitates lazy loading of hasMany relations.
 * A HasManyPlaceholder object behaves like an Collection containing all related objects from the repository, but only retrieves the objects on-access or on-change.
 *
 * @package ORM
 */
class HasManyPlaceholder extends Object implements \ArrayAccess, \Iterator, \Countable {

	/**
	 * @var string|Collection Initialy a reference "repository/model/property", but will be replaced with the referenced Collection
	 */
	private $__placeholder;

	/**
	 * @var stdClass  The instance this placeholder belongs to
	 */
	private $__container;

	function __construct($reference, $container) {
		$this->__placeholder = $reference;
		$this->__container = $container;
	}

	function __call($method, $args) {
		$this->replacePlaceholder();
		return call_user_func_array(array($this->__placeholder, $method), $args);
	}

	// @todo: mimic array errors and behaviour on propery access and method invocation
	// Array access
	function offsetExists($offset) {
		$this->replacePlaceholder();
		return $this->__placeholder->offsetExists($offset);
	}

	function offsetGet($offset) {
		$this->replacePlaceholder();
		return $this->__placeholder->offsetGet($offset);
	}

	function offsetSet($offset, $value) {
		$this->replacePlaceholder();
		return $this->__placeholder->offsetSet($offset, $value);
	}

	function offsetUnset($offset) {
		$this->replacePlaceholder();
		return $this->__placeholder->offsetUnset($offset);
	}

	// Iterator
	function rewind() {
		$this->replacePlaceholder();
		return $this->__placeholder->rewind();
	}

	function valid() {
		$this->replacePlaceholder(true);
		return $this->__placeholder->valid();
	}

	function current() {
		$this->replacePlaceholder(true);
		return $this->__placeholder->current();
	}

	function key() {
		$this->replacePlaceholder(true);
		return $this->__placeholder->key();
	}

	function next() {
		$this->replacePlaceholder(true);
		return $this->__placeholder->next();
	}

	// Countable
	function count() {
		$this->replacePlaceholder();
		return $this->__placeholder->count();
	}

	/**
	 * Replace the placeholder and return the array.
	 *
	 * @param bool $ignoreDuplicateReplacement A foreach($hasManyPlaceholder as $item) will call the iterator methods in this class instead of the Collection
	 * @return void
	 */
	private function replacePlaceholder($ignoreDuplicateReplacement = false) {
		if (is_string($this->__placeholder) === false) { // Is the __reference already replaced?
			if ($ignoreDuplicateReplacement === false) {
				notice('This placeholder is already replaced', 'Did you clone the object?');
			}
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
		$repo = getRepository($repositoryId);
		$repo->loadAssociation($model, $this->__container, $property);
		$this->__placeholder = PropertyPath::get($property, $this->__container);
	}

}

?>
