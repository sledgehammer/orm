<?php
/**
 * RepositoryBackend
 */
namespace Sledgehammer;
/**
 * The minimal interface for a (read-only) Repository Backend
 *
 * @package ORM
 */

abstract class RepositoryBackend extends Object {

	/**
	 * @var string
	 */
	public $identifier;

	/**
	 * The available models in this backend.
	 *
	 * @var array|ModelConfig  array('Model name' => ModelConfig, 'Model2 name' => ModelConfig, ...)
	 */
	public $configs;

	/**
	 * The junction tables
	 * @var array|ModelConfig
	 */
	public $junctions = array();

	/**
	 * Retrieve model-data by id.
	 *
	 * @return mixed data
	 */
	abstract function get($id, $config);

	/**
	 * Retrieve all available model-data
	 *
	 * @param array $config
	 * @return \Traversable|array
	 */
	function all($config) {
		throw new \Exception('Method: '.get_class($this).'->all() not implemented');
	}

	/**
	 * Retrieve all related model-data.
	 *
	 * @return \Traversable|array
	 */
	function related($config, $reference, $id) {
		return $this->all($config)->where(array($reference => $id));
	}

	/*
	 * @param array $relation  The hasMany relation
	 * @param mixed $id  The ID of the container instance.
	 * @return \Traversable|array
	 */
	function related_old($relation, $id) {
		dump($relation);

	}

	/**
	 * Update an existing record
	 *
	 * @param mixed $new
	 * @param mixed $old
	 * @param array $config
	 * @return mixed updated data
	 */
	function update($new, $old, $config) {
		throw new \Exception('Method: '.get_class($this).'->update() not implemented');
	}

	/**
	 * Add a new record
	 *
	 * @param mixed $data
	 * @param array $config
	 * @return mixed
	 */
	function add($data, $config) {
		throw new \Exception('Method: '.get_class($this).'->add() not implemented');
	}

	/**
	 * Permanently remove an record based on the data
	 *
	 * @param array $data  Might only contain the ID
	 * @param array $config
	 */
	function delete($data, $config) {
		throw new \Exception('Method: '.get_class($this).'->remove() not implemented');
	}

	/**
	 * Rename a model and remap the relations to the new name.
	 *
	 * @param string $from  Current name
	 * @param string $to    The new name
	 */
	function renameModel($from, $to) {
		foreach ($this->configs as $config) {
			if ($config->name === $from) {
				$config->name = $to;
				$config->plural = null;
			}
			foreach ($config->belongsTo as $key => $belongsTo) {
				if ($belongsTo['model'] == $from) {
					$config->belongsTo[$key]['model'] = $to;
				}
			}
			foreach ($config->hasMany as $key => $hasMany) {
				if ($hasMany['model'] == $from) {
					$config->hasMany[$key]['model'] = $to;
				}
			}
		}
	}

	/**
	 * Rename a property and remap the relations to the new name.
	 *
	 * @param string $model The modelname
	 * @param string $from  The current propertyname
	 * @param string $to    The new propertyname
	 */
	function renameProperty($model, $from, $to) {
		$config = $this->configs[$model];
		if (in_array($to, $config->getPropertyNames())) {
			notice('Overwriting existing property "'.$to.'"');
			// Unset original mapping
			$column = array_search($to, $config->properties);
			if ($column !== false) {
				unset($config->properties[$column]);
			} else {
				unset($config->belongsTo[$to]);
				unset($config->hasMany[$to]);
			}
		}
		if (array_key_exists($from, $config->defaults)) {
			$config->defaults[$to] = $config->defaults[$from];
			unset($config->defaults[$from]);
		}
		$column = array_search($from, $config->properties);
		if ($column !== false) { // A property?
			$config->properties[$column] = $to;
			return;
		}
		if (isset($config->belongsTo[$from])) { // A belongsTo relation?
			$config->belongsTo[$to] = $config->belongsTo[$from];
			unset($config->belongsTo[$from]);
			if (isset($config->belongsTo[$to]['model']) && isset($this->configs[$config->belongsTo[$to]['model']])) {
				$belongsToModel = $this->configs[$config->belongsTo[$to]['model']];
				foreach ($belongsToModel->hasMany as $property => $hasMany) {
					if ($hasMany['model'] === $model && $hasMany['belongsTo'] === $from) {
						$belongsToModel->hasMany[$property]['belongsTo'] = $to;
						break;
					}
				}
			}
			return;
		}
		if (isset($config->hasMany[$from])) { // A hasMany relation?
			$config->hasMany[$to] = $config->hasMany[$from];
			unset($config->hasMany[$from]);
			return;
		}
		notice('Property: "'.$from.' not found"');
	}
}
?>
