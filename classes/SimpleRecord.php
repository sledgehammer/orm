<?php
/**
 * Een Record die zelf (object)eigenschappen aanmaakt aan de hand van de kolommen in de database.
 *
 * @package Record
 */
namespace SledgeHammer;
class SimpleRecord extends Record {

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
	 * @param array $options
	 * @return Collection
	 */
	static function all($model, $options = array()) {
		$repository = value($options['repository']) ?: 'default';
		$repo = getRepository($repository);
		return $repo->loadCollection($model);
	}

	/**
	 *
	 * @param string $model (required)
	 * @param array $values
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 * )
	 * @return SimpleRecord
	 */
	static function create($model = null, $values = array(), $options = array()) {
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

	function delete() {
		if ($this->_model == 'SimpleRecord') {
		 	throw new \Exception('Model unknown, instance not loaded using SimpleRecord methods');
		}
		return parent::delete();
	}

	function save() {
		if ($this->_model == 'SimpleRecord') {
		 	throw new \Exception('Model unknown, instance not loaded or created using SimpleRecord methods');
		}
		return parent::save();
	}

	function getChanges() {
		if ($this->_model == 'SimpleRecord') {
		 	throw new \Exception('Model unknown, instance not loaded or created using SimpleRecord methods');
		}
		return parent::getChanges();
	}

	public function __set($property, $value) {
		if ($this->_state == 'constructed') {
			$this->$property = $value;
		} else {
			return parent::__set($property, $value);
		}
	}
}
?>
