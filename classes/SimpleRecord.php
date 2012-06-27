<?php
/**
 * SimpleRecord
 */
namespace Sledgehammer;
/**
 * An ActiveRecord with (generated) properties based on the ModelConfig->properties.
 * Adds the ActiveRecord interface to any Repository model, but disables support for property-autocompletion.
 *
 * @package ORM
 */
class SimpleRecord extends ActiveRecord {

	/**
	 * @param string $model
	 * @param mixed $conditions
	 * @param array $options array(
	 *   'repository' => (string) "default"
	 *   'preload' => (bool) false
	 * )
	 * @return SimpleRecord
	 */
	static function find($model, $conditions = null, $options = array()) {
		if (count(func_get_args()) < 2) {
			warning('SimpleRecord::find() requires minimal 2 parameters', 'SimpleRecord::find($model, $conditions, $options = array()');
		}
		$options['model'] = $model;
		return parent::find($conditions, $options);
	}
	/**
	 *
	 * @param string $model
	 * @param array $options
	 * @return Collection
	 */
	static function all($model = null, $options = array()) {
		if (count(func_get_args()) < 1) {
			warning('SimpleRecord::all() requires minimal 1 parameter', 'SimpleRecord::all($model, $options = array()');
		}
		$options['model'] = $model;
		return parent::all($options);
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
		if (count(func_get_args()) < 2) {
			warning('SimpleRecord::create() requires minimal 1 parameter', 'SimpleRecord::create($model, $values = array(), $options = array()');
		}
		$options['model'] = $model;
		return parent::create($values, $options);
	}

	public function __set($property, $value) {
		if ($this->_state == 'constructed') {
			$this->$property = $value; // Add properties on the fly (in the construction fase)
		} else {
			return parent::__set($property, $value);
		}
	}
}
?>
