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
	 * @param array $relation  The hasMany relation
	 * @param mixed $id  The ID of the container instance.
	 * @return \Traversable|array
	 */
	function related($relation, $id) {
		if (isset($relation['through']) === false) {
			// one-to-many relation?
			$conditions = array($relation['reference'] => $id);
		} else {
			// many-to-many relation.
			$junction = $this->junctions[$relation['through']];
			$join = $this->all($junction->backendConfig);
			if (($join instanceof Collection) === false) {
				$join = new Collection($join);
			}
			$ids = $join->where(array($relation['reference'] => $id))->select($relation['id'])->toArray();
			if (count($ids) === 0) {
				return new Collection(array());
			}
			$conditions = array('id IN' => $ids);
		}
		$config = $this->configs[$relation['model']];
		$all = $this->all($config->backendConfig);
		if (($all instanceof Collection) === false) {
			$all = new Collection($all);
		}
		return $all->where($conditions);
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
}
?>
