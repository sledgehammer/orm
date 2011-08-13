<?php
/**
 * RepositoryObject facilitates lazy loading of belongsTo realtions.
 * A RepositoryObject object behaves like the object from the repository, but only retrieves the real object on access or change.
 *
 * @package Record
 */
namespace SledgeHammer;
class RepositoryObject extends Object {
	/**
	 * @var mixed
	 */
	private $__object;
	
	/**
	 *
	 * @param type $config 
	 */
	function __construct($config) {
		$this->__object = $config;
	}
	
	public function __get($property) {
		if (is_object($this->__object)) {
			return $this->__object->$property;
		}
		if (array_key_exists($property, $this->__object['fields'])) {
			return $this->__object['fields'][$property]; // ->id
		}
		return $this->__getObject()->$property;
	}
	
	public function __set($property, $value) {
		$this->__getObject()->$property = $value;
	}
	
	public function __call($method, $arguments) {
		return call_user_func_array(array($this->__getObject(), $method) , $arguments);
	}


	/**
	 * Get the real object.
	 * @return Object
	 */
	private function __getObject() {
		if (is_object($this->__object)) {
			return $this->__object;
		}
		$config = $this->__object;
		$repo = getRepository($config['repository']);
		$this->__object = $repo->loadInstance($config['model'], $config['id']);
		return $this->__object;
	}
}

?>
