<?php
/**
 * ArrayRepositoryBackend
 */
namespace Sledgehammer;
/**
 * A RepositoryBackend for 1 model stored in an array.
 *
 * @package ORM
 */
class ArrayRepositoryBackend extends RepositoryBackend {

	public $identifier = 'array';

	/**
	 * @var array
	 */
	private $items;

	/**
	 * @param ModelConfig $config
	 * @param array $items
	 */
	function __construct($config, $items) {
		$this->configs[$config->name] = $config;
		$config->backendConfig = array(
			'indexed' => (count($config->id) === 0)
		);
		reset($items);
		$row = current($items);
		if ($row !== null) { // The $items array is NOT empty
			if (is_object($row)) {
				$row = get_object_vars($row); // Convert to array
			}
			if (count($config->properties) == 0) { // No "column => property" mapping defined?
				// Generate a direct 1 on 1 mapping based on the first item.
				$columns = array_keys($row);
				$config->properties = array_combine($columns, $columns);
			}
			if (count($config->id) === 0 && array_key_exists('id', $row) === false) { // 'id' field detected in the first row?
				$config->id = array('id');
				$config->backendConfig['indexed'] = true;
				foreach (array_keys($items) as $index) {
					$items[$index]['id'] = $index;
				}
				if (array_key_exists('id', $config->properties) == false) {
					array_key_unshift($config->properties, 'id', 'id');
				}
				$config->backendConfig['key'] = 'id';
			} elseif (count($config->id) == 1) { // Only 1 field as id?
				// Copy the data using the id as index
				$config->backendConfig['indexed'] = true;
				$indexField = $config->id[0];
				$config->backendConfig['key'] = $indexField;
				$clone = array();
				foreach ($items as $row) {
					$key = PropertyPath::get($indexField, $row);
					$clone[$key] = $row;
				}
				$items = $clone;
			}
		}
		$this->items = $items;
	}

	/**
	 * @param mixed $id
	 * @param array $config backendConfig
	 */
	function get($id, $config) {
		$key = $this->getKey($id, $config);
		$row = $this->items[$key];
		if ($row === null) {
			throw new \Exception('Element ['.$key.'] not found');
		}
		return $row;
	}

	function all($config) {
		return array_values($this->items);
	}

	function update($new, $old, $config) {
		$key = $this->getKey($old, $config);
		if ($this->items[$key] !== $old) {
			throw new \Exception('No matching record found, repository has outdated info');
		}
		$this->items[$key] = $new;
		return $new;
	}

	function add($data, $config) {
		$key = PropertyPath::get($config['key'], $data);
		if ($key === null) {
			$this->items[] = $data;
			$keys =array_keys($this->items);
			$key = array_pop($keys);
			$key = PropertyPath::set($config['key'], $key, $data);
			return $data;
		}


//		$key = $id[$config['key']];
		$this->getKey($data, $config);
		return $data;
	}

	/**
	 *
	 * @param type $id
	 * @throws Exception
	 */
	private function getKey($id, $config) {
		if ($config['indexed'] == false) {
			throw new \Exception('Not (yet) supported');
		}
		if (is_array($id)) {
			$key = $id[$config['key']];
		} else {
			$key = $id;
		}
		if ($key === null) {
			throw new InfoException('Invalid ID', $id);
		}
		return $key;
	}

}

?>
