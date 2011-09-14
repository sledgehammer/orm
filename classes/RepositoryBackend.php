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
}

?>
