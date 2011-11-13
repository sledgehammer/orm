<?php
/**
 * This Placeholder facilitates lazy loading of hasMany relations.
 * A HasManyPlaceholder object behaves like an array containing all related objects from the repository, but only retrieves the objects on-access or on-change.
 *
 * @package Record
 */
namespace SledgeHammer;

class HasManyPlaceholder extends Object implements \ArrayAccess, \Iterator, \Countable {

	/**
	 * @var string|\ArrayIterator  "repository/model/property" 
	 */
	private $__reference;

	/**
	 * @var stdClass  The instance this placeholder belongs to
	 */
	private $__container;

	/**
	 * @var ArrayIterator
	 */
	private $__iterator = null;

	function __construct($reference, $container) {
		$this->__reference = $reference;
		$this->__container = $container;
	}

	// @todo: mimic array errors and behaviour on propery access and method invocation
	// Array access
	function offsetExists($offset) {
		$array = $this->replacePlaceholder();
		return isset($array[$offset]);
	}

	function offsetGet($offset) {
		$array = $this->replacePlaceholder();
		return $array[$offset];
	}

	function offsetSet($offset, $value) {
		$array = &$this->replacePlaceholder();
		$array[$offset] = $value;
	}

	function offsetUnset($offset) {
		$array = &$this->replacePlaceholder();
		unset($array[$offset]);
	}

	// Iterator
	function rewind() {
		if ($this->__iterator === null) {
			$array = $this->replacePlaceholder();
			$this->__iterator = new \ArrayIterator($array);
		}
		$this->__iterator->rewind();
	}

	function valid() {
		if ($this->__iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__iterator->valid();
	}

	function current() {
		if ($this->__iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__iterator->current();
	}

	function key() {
		if ($this->__iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__iterator->key();
	}

	function next() {
		if ($this->__iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->__iterator->next();
	}

	// Countable
	function count() {
		$array = $this->replacePlaceholder();
		return count($array);
	}

	/**
	 * Replace the placeholder and return the array.
	 *
	 * @return array
	 */
	private function &replacePlaceholder() {
		$parts = explode('/', $this->__reference);
		$repositoryId = array_shift($parts);
		$model = array_shift($parts);
		$property = implode('/', $parts);
		$self = &PropertyPath::getReference($this->__container, $property);
		if ($self !== $this) {
			notice('This placeholder belongs to an other (cloned?) container');
			return $self;
		}
		$repo = getRepository($repositoryId);
		$repo->loadAssociation($model, $this->__container, $property);
		$self = &PropertyPath::getReference($this->__container, $property);
		return $self;
	}

}

?>
