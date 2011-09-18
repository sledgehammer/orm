<?php
/**
 * Een Record die zelf (object)eigenschappen aanmaakt aan de hand van de kolommen in de database.
 *
 * @package Record
 */
namespace SledgeHammer;
class SimpleRecord extends Object {

	private $_model;
	private $_state = 'unconstructed';
	private $_repository = '__not_set__';

	function __construct() {
		$this->_state = 'constructed';
	}

	/**
	 *
	 * @param string $model
	 * @param mixed $id
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 *   'preload' => (bool) false
	 * )
	 * @return SimpleRecord
	 */
	static function findById($model, $id, $options = array()) {
		$repository = value($options['repository']) ?: 'default';
		$repo = getRepository($repository);
		$instance = $repo->get($model, $id, value($options['preload']));
		if ($instance instanceof SimpleRecord) {
			$instance->_state = 'retrieved';
			$instance->_repository = $repository;
			$instance->_model = $model;
			return $instance;
		}
		throw new \Exception('Model "'.$model.'" isn\'t configured as SimpleRecord');
	}

	/**
	 *
	 * @param string $model
	 * @param array $values
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 * )
	 * @return SimpleRecord
	 */
	static function create($model, $values = array(), $options = array()) {
		$repository = value($options['repository']) ?: 'default';
		$repo = getRepository($repository);
		$instance = $repo->create($model, $values);
		if ($instance instanceof SimpleRecord) {
			$instance->_state = 'new';
			$instance->_repository = $repository;
			$instance->_model = $model;
			return $instance;
		}
		throw new \Exception('Model "'.$model.'" isn\'t configured as SimpleRecord');
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

			case 'constructed':
				$this->$property = $value;
				break;

			case 'deleted':
				notice('A deleted Record has no properties');
				break;

			default:
				return parent::__set($property, $value);
		}
	}

	function save() {
		if ($this->_state == 'deleted') {
			throw new \Exception(__CLASS__.'->save() not allowed on deleted objects');
		}
		$repo = getRepository($this->_repository);
		$repo->save($this->_model, $this);
	}

	function delete() {
		$repo = getRepository($this->_repository);
		$repo->remove($this->_model, $this);
		// unset properties
		$propertyWhitelist = array_keys(get_class_vars(__CLASS__));
		foreach (get_object_vars($this) as $property => $value) {
			if (in_array($property, $propertyWhitelist) == false) {
				unset($this->$property);
			}
		}
		$this->_state = 'deleted';
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
