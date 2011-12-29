<?php
/**
 * This Placeholder facilitates lazy loading of hasMany relations.
 * A HasManyPlaceholder object behaves like an Collection containing all related objects from the repository, but only retrieves the objects on-access or on-change.
 *
 * @package Record
 */
namespace SledgeHammer;

class HasManyPlaceholder extends Object implements \ArrayAccess, \Iterator, \Countable {

	/**
	 * @var string "repository/model/property"
	 */
	private $__reference;

	/**
	 * @var stdClass  The instance this placeholder belongs to
	 */
	private $__container;

	/**
	 * @var Collection
	 */
	private $__collection = null;

	function __construct($reference, $container) {
		$this->__reference = $reference;
		$this->__container = $container;
	}

	function __call($method, $args) {
		$this->replacePlaceholder();
		return call_user_func_array(array($this->__collection, $method), $args);
	}

	// @todo: mimic array errors and behaviour on propery access and method invocation
	// Array access
	function offsetExists($offset) {
		$this->replacePlaceholder();
		return $this->__collection->offsetExists($offset);
	}

	function offsetGet($offset) {
		$this->replacePlaceholder();
		return $this->__collection->offsetGet($offset);
	}

	function offsetSet($offset, $value) {
		$this->replacePlaceholder();
		return $this->__collection->offsetSet($offset, $value);
	}

	function offsetUnset($offset) {
		$this->replacePlaceholder();
		return $this->__collection->offsetUnset($offset);
	}

	// Iterator
	function rewind() {
		if ($this->__collection === null) {
			$this->replacePlaceholder();
		}
		return $this->__collection->rewind();
	}

	function valid() {
		if ($this->__collection === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__collection->valid();
	}

	function current() {
		if ($this->__collection === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__collection->current();
	}

	function key() {
		if ($this->__collection === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__collection->key();
	}

	function next() {
		if ($this->__collection === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__collection->next();
	}

	// Countable
	function count() {
		$this->replacePlaceholder();
		return $this->__collection->count();
	}

	/**
	 * Replace the placeholder and return the array.
	 */
	private function replacePlaceholder() {
		$parts = explode('/', $this->__reference);
		$repositoryId = array_shift($parts);
		$model = array_shift($parts);
		$property = implode('/', $parts);
		$data = PropertyPath::get($this->__container, $property);
		if ($data !== $this) {
			notice('This placeholder belongs to an other (cloned?) container');
			$this->__collection = $data;
			return;
		}
		$repo = getRepository($repositoryId);
		$repo->loadAssociation($model, $this->__container, $property);
		$this->__collection = PropertyPath::get($this->__container, $property);
	}

}

?>
