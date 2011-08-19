<?php
/**
 * This Placeholder facilitates lazy loading of hasMany relations.
 * A HasManyPlaceholder object behaves like an array containing all related objects from the repository, but only retrieves the objects on-access or on-change.
 *
 * @package Record
 */
namespace SledgeHammer;
class HasManyPlaceholder extends Object implements \ArrayAccess, \Iterator {
	/**
	 * @var array
	 */
	private $__config;
	
	/**
	 *
	 * @param type $config 
	 */
	function __construct($config) {
		$this->__config = $config;
	}
	
	public function __get($property) {
		parent::__get($property);
	}
	
	public function __set($property, $value) {
		parent::__set($property, $value);
	}
	
	public function __call($method, $arguments) {
		parent::__call($method, $arguments);
	}
	
	public function offsetExists($offset) {
		;
	}
	public function offsetGet($offset) {
		;
	}
	public function offsetSet($offset, $value) {
		;
	}
	public function offsetUnset($offset) {
		;
	}
	
	public function current() {
		;
	}
	public function key() {
		;
	}
	public function next() {
		;
	}
	public function rewind() {
		;
	}
	public function valid() {
		;
	}

	/**
	 * Replace the placeholder and return the real object.
	 * @return Object
	 */
	private function __replaceProperty() {
		$config = $this->__config;
		$repo = getRepository($config['repository']);
		$instance = $repo->loadInstance($config['model'], $config['id']);
		$container = $repo->loadInstance($config['container']['model'], $config['container']['id']);
		$property = $config['container']['property'];
		$container->$property = $instance;
		return $instance;
	}
}

?>
