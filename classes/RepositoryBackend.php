<?php
/**
 * The minimal interface for a (read-only) Repository Backend
 *
 * @package Record
 */
namespace SledgeHammer;
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
	 * Retrieve model-data by id.
	 *
	 * @return stdClass instance
	 */
	abstract function get($id, $config);

	/**
	 * Retrieve all available model-data
	 *
	 * @param array $config
	 * @return Collection
	 */
	function all($config) {
		throw new \Exception('Method: '.get_class($this).'->all() not implemented');
	}

	/**
	 * Update an existing record
	 *
	 * @param mixed $new
	 * @param mixed $old
	 * @param array $config
	 * @return mixed
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
}
?>
