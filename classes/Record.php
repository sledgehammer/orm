<?php
/**
 * Record, an ActiveRecord frontend for the Repository
 *
 */
namespace SledgeHammer;
abstract class Record extends Object {

	protected $_model;
	protected $_state = 'unconstructed';
	protected $_repository = 'default';

	function __construct() {
		$this->_state = 'constructed';
		if ($this->_model === null) {
			// Detect modelname based on the classname
			$parts = explode('\\', get_class($this));
			$this->_model = array_pop($parts);
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
		$repository = value($options['repository']) ?: 'default';
		$repo = getRepository($repository);
		$instance = $repo->create($this->_model, $values);
		if ($instance instanceof Record) {
			$instance->_state = 'new';
			$instance->_repository = $repository;
			return $instance;
		}
		throw new \Exception('Model "'.$this->_model.'" isn\'t configured as Record');
	}

	function __get($property) {
		if ($this->_state == 'deleted') {
			notice('A deleted Record has no properties');
			return null;
		} else {
			return parent::__get($property);
		}
	}

	public function __set($property, $value) {
		switch ($this->_state) {

			case 'deleted':
				notice('A deleted Record has no properties');
				break;

			default:
				return parent::__set($property, $value);
		}
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
		$retval = $repo->remove($this->_model, $this);
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
}
?>
