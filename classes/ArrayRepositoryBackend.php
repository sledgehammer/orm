<?php
namespace SledgeHammer;
/**
 * ArrayRepositoryBackend
 * A RepositoryBackend for 1 model stored in an array.
 */
class ArrayRepositoryBackend extends RepositoryBackend {

	public $identifier = 'array';

	/**
	 * @var Collection
	 */
	private $data;

	/**
	 * @param ModelConfig $config
	 * @param array $data
	 */
	function __construct($config, $data, $options = array()) {
		$this->configs[] = $config;
		reset($data);
		$row = current($data);
		if (count($config->properties) == 0) { // auto detect column => property mapping?
			$columns = array_keys($row);
			$config->properties = array_combine($columns, $columns);
		}
		if ($config->id ===  array('id') && array_key_exists('id', $row) === false) { // Use index as id?
			$config->backendConfig['indexed'] = true;
			foreach (array_keys($data) as $index) {
				$data[$index]['id'] = $index;
			}
			if (array_key_exists('id', $config->properties) == false) {
				array_key_unshift($config->properties, 'id', 'id');
			}
		} elseif (count($config->id) == 1) {
			$config->backendConfig['indexed'] = true;
			$dataCopy = $data;
			$indexField = $config->id[0];
			foreach ($dataCopy as $row) {
				$data[$row[$indexField]] = $row;
			}
		}
		$this->data = collection($data);
	}

	/**
	 * @param mixed $id
	 * @param ModelConfig $config
	 */
	function get($id, $config) {
		if ($config['indexed'] == false) {
			throw new Exception('Not supported');
		}
		$row = $this->data[$id];
		if ($row === null) {
			throw new \OutOfBoundsException('Element ['.$id.'] not found');
		}
		return $row;
	}

	function all($config) {
		return $this->data->selectKey(null);
	}

}

?>
