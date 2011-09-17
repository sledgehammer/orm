<?php
/**
 * The minimal interface for a (read-only) Repository Backend
 *
 * @package Record
 */
namespace SledgeHammer;
abstract class RepositoryBackend extends Object {

	/**
	 * Return the available models in this backend.
	 *
	 * @return array  array('model name' => array()
	 */
	abstract function getModels();

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
		throw new \Exception('Not implemented');
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
		throw new \Exception('Not implemented');
	}

	/**
	 * Add a new record
	 *
	 * @param mixed $data
	 * @param array $config
	 * @return mixed
	 */
	function add($data, $config) {
		throw new \Exception('Not implemented');
	}

	/**
	 * Permanently remove the data
	 *
	 * @param mixed $data
	 * @param array $config
	 */
	function remove($data, $config) {
		throw new \Exception('Not implemented');
	}
}
?>
