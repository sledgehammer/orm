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
	 * @var array
	 */
	private $config;
	
	private $iterator;
	
	/**
	 *
	 * @param type $config 
	 */
	function __construct($config) {
		$this->config = $config;
	}
	// mimic array errors and behaviour
	public function __get($property) {
		parent::__get($property);
	}
	
	public function __set($property, $value) {
		parent::__set($property, $value);
	}
	
	public function __call($method, $arguments) {
		parent::__call($method, $arguments);
	}
	// Array access
	public function offsetExists($offset) {
		$array = $this->replacePlaceholder();
		return isset($array[$offset]);
	}
	public function offsetGet($offset) {
		$array = $this->replacePlaceholder();
		return $array[$offset];
	}
	public function offsetSet($offset, $value) {
		$array = &$this->replacePlaceholder();
		$array[$offset] = $value;
	}
	public function offsetUnset($offset) {
		$array = &$this->replacePlaceholder();
		unset($array[$offset]);
	}
	// iterator
	public function current() {
		if ($this->iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->iterator->current();
	}
	public function key() {
		if ($this->iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->iterator->key();
	}
	public function next() {
		if ($this->iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->iterator->next();
	}
	public function rewind() {
		$array = $this->replacePlaceholder();
		$this->iterator = new \ArrayIterator($array);
	}
	
	public function valid() {
		if ($this->iterator === null) {
			throw new \Exception('Not implemented');
		}
		return $this->iterator->valid();
	}
	
	public function count() {
		$array = $this->replacePlaceholder();
		return count($array);
	}

	/**
	 * Replace the placeholder and return the array.
	 * @return array
	 */
	private function &replacePlaceholder() {
		$config = $this->config;
		$repo = getRepository($config['repository']);
		$container = $repo->loadInstance($config['container']['model'], $config['container']['id']);
		$property = $config['container']['property'];
		$container->{$property} = $config['collection']->all();
		return $container->{$property};
	}
}

?>
