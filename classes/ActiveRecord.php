<?php
/**
 * ActiveRecord
 */
namespace Sledgehammer;
/**
 * An ActiveRecord frontend for the Repository
 *
 * @package ORM
 */
abstract class ActiveRecord extends Observable {

	protected $_model;
	protected $_state = 'unconstructed';
	protected $_repository;
	protected $events = array(
		'create' => array(), // When a new instance is created (with initial data), but commited to the backend.
		'load' => array(), // After the data from the backend is injected into the ActiveRecord.
		'saving' => array(), // Before the data is sent to the backend
		'saved' => array(), // After the data is sent to the backend
		'deleting' => array(), // Before the delete operation is sent to the backend
		'deleted' => array(), // When the Record is deleted
	);

	function __construct() {
		$this->_state = 'constructed';
		if ($this->_model === null) {
			$this->_model = static::_getModel();
		}
		if (count(func_get_args()) != 0) {
			$model = $this->_model;
			throw new \Exception('Parameters not allowed for "new '.get_class($this).'()", use $'.strtolower($model).' = repository->get'.$model.'($id); or  $'.strtolower($model).' = '.$model.'::find($id)');
		}
	}

	/**
	 *
	 * @param string $model
	 * @param array $values
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 * )
	 * @return ActiveRecord
	 */
	static function create($values = array(), $options = array()) {
		$model = static::_getModel($options);
		$repositoryId = static::_getRepostoryId($options);
		$repo = getRepository($repositoryId);
		$instance = $repo->create($model, $values);
		if (get_class($instance) != get_called_class()) {
			throw new \Exception('Model "'.$model.'"('.get_class($instance).') isn\'t configured as "'.get_called_class());
		}
		return $instance;
	}

	/**
	 *
	 * @param type $conditions
	 * @param array $options
	 * @return ActiveRecord
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
		return $repo->delete($this->_model, $this);
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

	protected function onCreate($sender, $options) {
		if (isset($options['repository'])) {
			$this->_repository = $options['repository'];
		}
		if (isset($options['model'])) {
			$this->_model = $options['model'];
		}
		$this->_state = 'new';
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
	protected function onDeleted() {
		$this->_state = 'deleted';
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
