<?php
/**
 * Record, an ActiveRecord frontend for the Repository
 *
 * @package Record
 */
namespace SledgeHammer;

abstract class Record extends Observable {

	protected $_model;
	protected $_state = 'unconstructed';
	protected $_repository;
	protected $events = array(
		'load' => array(),
		'save' => array(),
		'saveComplete' => array(),
	);

	function __construct() {
		$this->_state = 'constructed';
		if ($this->_model === null) {
			$this->_model = static::_getModel();
		}
		if (count(func_get_args()) != 0) {
			$model = $this->_model;
			throw new \Exception('No parameters not allowed for "new '.get_class($this).'()", use $'.strtolower($model).' = repository->get'.$model.'($id); or  $'.strtolower($model).' = '.$model.'::find($id)');
		}
	}

	/**
	 *
	 * @param string $model
	 * @param array $values
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 * )
	 * @return Record
	 */
	static function create($values = array(), $options = array()) {
		$model = static::_getModel($options);
		$repositoryId = static::_getRepostoryId($options);
		$repo = getRepository($repositoryId);
		$instance = $repo->create($model, $values);
		if (get_class($instance) != get_called_class()) {
			throw new \Exception('Model "'.$model.'"('.get_class($instance).') isn\'t configured as "'.get_called_class());
		}
		$instance->_model = $model;
		$instance->_repository = $repositoryId;
		$instance->_state = 'new';
		return $instance;
	}

	/**
	 *
	 * @param type $conditions
	 * @param array $options
	 * @return Record
	 */
	static function find($conditions, $options = array()) {
		$model = static::_getModel($options);
		$repositoryId = static::_getRepostoryId($options);
		$repo = getRepository($repositoryId);
		if (is_array($conditions)) {
			$collection = $repo->all($model)->where($conditions);
			$count = $collection->count();
			if ($count == 0) {
				throw new \Exception('No "'.$model.'" model matches the conditions');
			}
			if ($count != 1) {
				throw new \Exception('More than 1 "'.$model.'" model matches the conditions');
			}
			$collection->rewind();
			$instance = $collection->current();
		} else {
			$instance = $repo->get($model, $conditions);
		}
		if (get_class($instance) != get_called_class()) {
			throw new \Exception('Model "'.$model.'"('.get_class($instance).') isn\'t configured as "'.get_called_class());
		}
		return $instance;
	}

	static function all($options = array()) {
		$model = static::_getModel($options);
		$repositoryId = static::_getRepostoryId($options);
		$repo = getRepository($repositoryId);
		return $repo->all($model);
	}

	function save() {
		if ($this->_state == 'deleted') {
			throw new \Exception(get_class($this).'->save() not allowed on deleted objects');
		}
		$repo = getRepository($this->_repository);
		return $repo->save($this->_model, $this);
	}

	function delete() {
		$repo = getRepository($this->_repository);
		$retval = $repo->delete($this->_model, $this);
		// unset properties
		$propertyWhitelist = array_keys(get_class_vars(__CLASS__));
		foreach (get_object_vars($this) as $property => $value) {
			if (in_array($property, $propertyWhitelist) == false) {
				unset($this->$property);
			}
		}
		$this->_state = 'deleted';
		return $retval;
	}

	/**
	 *
	 * @return array
	 */
	function getChanges() {
		$repo = getRepository($this->_repository);
		return $repo->diff($this->_model, $this);
	}

	protected static function _getModel($options = array()) {
		if (isset($options['model'])) {
			// Use the model given in the parameters
			return $options['model'];
		}
		$class = get_called_class();
		$properties = get_class_vars($class);
		if ($properties['_model'] !== null) {
			return $properties['_model'];
		} else {
			// Detect modelname based on the classname
			$parts = explode('\\', $class);
			return array_pop($parts);
		}
	}

	function __get($property) {
		if ($this->_state == 'deleted') {
			notice('A deleted Record has no properties');
			return null;
		} else {
			return parent::__get($property);
		}
	}

	function __set($property, $value) {
		if ($this->_state == 'deleted') {
			notice('A deleted Record has no properties');
		} else {
			return parent::__set($property, $value);
		}
	}

	protected function onLoad($sender, $options) {
		if (isset($options['repository'])) {
			$this->_repository = $options['repository'];
		}
		if (isset($options['model'])) {
			$this->_model = $options['model'];
		}
		$this->_state = 'loaded';
	}

	/**
	 *
	 * @param array $options
	 * @return Repository
	 */
	protected static function _getRepostoryId($options = array()) {
		if (isset($options['repository'])) {
			// Use the repository given in the parameters
			return $options['repository'];
		}
		$class = get_called_class();
		$properties = get_class_vars($class);
		if ($properties['_repository'] !== null) {
			// Use the repository defined in de subclass
			return $properties['_repository'];
		}
		return 'default'; // Use the default repository
	}

}

?>
