<?php
/**
 * Model configuration class, a formal definition of a Repository model.
 * 
 * 
 * @package Record
 */
namespace SledgeHammer;
class ModelConfig extends Object {

	/**
	 * @var string  The name of the model
	 */
	public $name;
	/**
	 * @var string  The full classname
	 */
	public $class;
	/**
	 * @var array  Direct mapping of properties to the backend data structure
	 */
	public $properties;
	/**
	 * @var array  The element(s) in the backend data that identifies an instance 
	 */
	public $id = array('id');
	/**
	 * @var array  Configuration of the belongsTo relation(s)
	 */
	public $belongsTo = array();
	/**
	 * @var array  Configuration of the hasMany relation(s)
	 */
	public $hasMany = array();
	/**
	 * @var array  Default values for new instance
	 */
	public $defaults = array();
	
	/**
	 * @var string 
	 */
	public $backend;
	/**
	 * @var mixed  An container for RepositoryBackend specific settings.
	 */
	public $backendConfig;
	
	/**
	 *
	 * @param string $name  Model name
	 * @param array $options  Additional configuration options
	 */
	function __construct($name, $options = array()) {
		$this->name = $name;
		foreach ($options as $property => $value) {
			$this->$property = $value;
		}
	}
}

?>
