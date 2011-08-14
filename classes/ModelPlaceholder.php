<?php
/**
 * ModelPlaceholder facilitates lazy loading of belongsTo realtions.
 * A ModelPlaceholder object behaves like the object from the repository, but only retrieves the real object on access or change.
 *
 * @package Record
 */
namespace SledgeHammer;
class ModelPlaceholder extends Object {
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
		if (array_key_exists($property, $this->__config['properties'])) {
			return $this->__config['properties'][$property]; // ->id
		}
		return $this->__getModel()->$property;
	}
	
	public function __set($property, $value) {
		$this->__getModel()->$property = $value;
	}
	
	public function __call($method, $arguments) {
		return call_user_func_array(array($this->__getModel(), $method) , $arguments);
	}


	/**
	 * Replace the placeholder and return the real object.
	 * @return Object
	 */
	private function __getModel() {
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
