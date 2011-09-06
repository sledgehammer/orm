<?php
/**
 * Repository/DataMapper
 *
 * @package Record
 */

namespace SledgeHammer;

class Repository extends Object {

	protected $id;
	protected $namespaces = array('', 'SledgeHammer\\');
	// model => config
	protected $configs = array();
	// references to instances
	protected $objects = array();
	
	protected $backends = array();

	function __construct() {
		$this->id = uniqid('R');
		$GLOBALS['Repositories'][$this->id] = &$this;
	}

	function __call($method, $arguments) {
		if (preg_match('/^get(.+)Collection$/', $method, $matches)) {
			if (count($arguments) > 0) {
				notice('Too many arguments, expecting none', $arguments);
			}
			return $this->loadCollection($matches[1]);
		}
		if (preg_match('/^(get|save|remove|add)(.+)$/', $method, $matches)) {
			$method = $matches[1];
			array_unshift($arguments, $matches[2]);
			return call_user_func_array(array($this, $method), $arguments);
		}
		return parent::__call($method, $arguments);
	}

	/**
	 * Retrieve an instance from the Repository
	 *
	 * @param string $model
	 * @param mixed $id  The instance ID
	 * @param bool $preload  Load relations direct
	 * @return instance
	 */
	function get($model, $id, $preload = false) {
		if ($id === null) {
			throw new \Exception('Parameter $id is required');
		}
		$config = $this->toConfig($model);

		$key = $this->toKey($id, $config);
		$instance = @$this->objects[$model][$key]['instance'];
		if ($instance !== null) {
			return $instance;
		}
		$data = $this->toBackend($config)->get($id, $config);
		return $this->create($model, $data, $preload);
	}

	/**
	 * Create a instance from existing $data.
	 * This won't store the data. For storing data use $repository->save($instance)
	 *
	 * @param string $model
	 * @param array/object $data Raw data from the backend
	 * @param bool $preload  Load relations direct
	 * @return instance
	 */
	function create($model, $data, $preload = false) {
		if ($data === null) {
			throw new \Exception('Parameter $data is required');
		}
		$config = $this->toConfig($model);
		$id = $this->toId($data, $config);
		$key = $this->toKey($id, $config);

		$instance = @$this->objects[$model][$key]['instance'];
		if ($instance !== null) {
			// @todo validate existing data
			return $instance;
		}
		$key = $this->toKey($id, $config);
		
		// Create new instance
		$definition = $config['class'];
		$instance = new $definition();
		$this->objects[$model][$key] = array(
			'instance' => $instance,
			'data' => $data,
			'state' => 'loading',
			'references' => array()
		);

		// Map the data onto the instance
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) {
				$instance->$property = $data[$relation];
			} else {
				switch ($relation['type']) {

					case 'belongsTo':
						$belongsToId = $data[$relation['reference']];
						if ($belongsToId != null) {
							if (empty($relation['model'])) {
								warning('Unable to determine model for property "' . $property . '"');
							}
							$belongsToInstance = @$this->objects[$relation['model']][$belongsToId]['instance'];
							if ($belongsToInstance !== null) {
								$instance->$property = $belongsToInstance;
							} else {
								if ($preload) {
									$instance->$property = $this->get($relation['model'], $belongsToId, $preload);
								} else {
									$instance->$property = new BelongsToPlaceholder(array(
										'repository' => $this->id,
										'model' => $relation['model'],
										'id' => $belongsToId,
										'fields' => array(
											$relation['id'] => $belongsToId
										),
										'property' => $property,
										'container' => $instance,
									));
								}
							}
						}
						break;

					case 'hasMany':
						if ($preload) {
							$this->loadAssociation($model, $instance, $property);
						} else {
							$instance->$property = new HasManyPlaceholder(array(
								'repository' => $this->id,
								'container' => array(
									'model' => $model,
									'id' => $id,
								),
								'property' => $property,
							));
						}
						break;

					default:
						throw new \Exception('Invalid mapping type: "' . $relation['type'] . '"');
				}
			}
		}
		$this->objects[$model][$key]['state'] = 'loaded';
		return $instance;
	}

	/**
	 *
	 * @param string $model
	 * @return Collection
	 */
	function loadCollection($model) {
		$config = $this->toConfig($model);
		$config['repository'] = $this->id;
		// @todo support for multiple backends
		$sql = select('*')->from($config['table']);
		$collection = new DatabaseCollection($sql, $config['dbLink']);
		$collection->bind($model, $this->id);
		return $collection;
	}

	function loadAssociation($model, $instance, $property) {
		$config = $this->toConfig($model);
		if (count($config['id']) != 1) {
			throw new \Exception('Complex keys not (yet) supported for hasMany relations');
		}
		$relation = $config['mapping'][$property];
		$id = $instance->{$config['id'][0]};
		// @todo support for multiple backends, by using the (pure) Collection methods

		$collection = $this->loadCollection($relation['model']);
		$collection->sql = $collection->sql->andWhere($relation['reference'] . ' = ' . $id);
		$items = $collection->asArray();
		$this->objects[$model][$id]['references'][$property] = $items; // Add a copy for change detection
		$instance->$property = $items;
	}

	/**
	 * Remove the instance
	 *
	 * @param string $model
	 * @param instance $instance
	 */
	function remove($model, $instance) {
		$config = $this->toConfig($model);
		$key = $this->toKey($instance, $config);
		$object = @$this->objects[$model][$key];
		if ($object === null) {
			throw new \Exception('The instance is not bound to this Repository');
		}

//		$data = $this->toData($instance, $config['mapping']);
//		if (serialize($data) != serialize($object['data'])) {
//			throw new \Exception('The instance contains unsaved changes'); // Should we throw this Exception here?
//		}
		// @todo add multiple backends
		$this->toBackend($config)->remove($object['data'], $config);
		$this->objects[$model][$key]['state'] = 'removed';
	}

	/**
	 * Store the instance
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @param bool $ignoreRelations  false: Save all connected instances, true: only save this instance
	 */
	function save($model, $instance, $ignoreRelations = false) {
		$config = $this->toConfig($model);
		$key = $this->toKey($instance, $config);
		$current = @$this->objects[$model][$key];
		if ($current !== null && $current['state'] == 'saving') { // Voorkom oneindige recursion
			return;
		}
		$this->objects[$model][$key]['state'] = 'saving';
		try {
			$hasMany = array();
			$data = array();
			// Map to data and save  the belongsTo instances
			foreach ($config['mapping'] as $property => $relation) {
				if (is_string($relation)) { // direct property to column mapping
					if (property_exists($instance, $property)) {
						$data[$relation] = $instance->$property;
					}
				} else {
					switch ($relation['type']) {

						case 'hasMany':
							if ($ignoreRelations) {
								break;
							}
							$relation['collection'] = $instance->$property;
							$hasMany[$property] = $relation;

							break;

						case 'belongsTo':
							if ($ignoreRelations) {
								break;
							}
							$belongsTo = $instance->$property;
							if (($belongsTo instanceof BelongsToPlaceholder) == false) {
								$this->save($relation['model'], $belongsTo);
							}
							$idProperty = $relation['id'];
							$data[$relation['reference']] = $belongsTo->$idProperty;
							break;

						default:
							throw new \Exception('Invalid mapping type: "' . $relation['type'] . '"');
					}
				}
			}
			// Save the instance
			if ($current === null) { // New instance?
				$this->toBackend($config)->add($data, $config);
				$this->objects[$model][$key]['instance'] = $instance;
				$this->objects[$model][$key]['data'] = $data;
			} else { // Existing instance?
				if ($current['instance'] !== $instance) {
					// @todo ID change detection
					throw new \Exception('The instance is not bound to this Repository');
				}
				$this->toBackend($config)->update($data, $current['data'], $config);
				$this->objects[$model][$key]['data'] = $data;
			}
			// Save the connected instances
			$this->objects[$model][$key]['state'] = 'saving';
			foreach ($hasMany as $property => $relation) {
				if (($relation['collection'] instanceof HasManyPlaceholder)) {
					continue; // No changes (It's not even accessed)
				}
				$relationConfig = $this->toConfig($relation['model']);
				$collection = $relation['collection'];
				if ($collection instanceof \Iterator) {
					$collection = iterator_to_array($collection);
				}
				foreach ($collection as $item) {
					// Connect the item to this instance
					foreach ($relationConfig['mapping'] as $property2 => $relation2) {
						if ($relation2['type'] == 'belongsTo' && $relation['reference'] == $relation2['reference']) {
							$item->$property2 = $instance;
						}
					}
					$this->save($relation['model'], $item);
				}
				// Delete items that are no longer in the relation
				$old = $this->objects[$model][$key]['references'][$property];
				foreach ($old as $item) {
					if (array_search($item, $collection, true) === false) {
						$this->remove($relation['model'], $item);
					}
				}
			}
		} catch (\Exception $e) {
			$this->objects[$model][$key]['state'] = 'unknown';
			throw $e;
		}
		$this->objects[$model][$key]['state'] = 'saved';
	}
	
	function registerBackend($backend, $id = null) {
		if ($id === null) {
			$id = uniqid('B');
		}
		$this->backends[$id] = $backend;
		$configs = $backend->getModels();
		foreach ($configs as $model => $config) {
			$config['backend'] = $id;
			$this->register($model, $config);
		}
		
	}

	function isConfigured($model) {
		return isset($this->configs[$model]);
	}
	
	protected function register($model, $config) {
		$config['model'] = $model;
		if (empty($config['class'])) {
			$config['class'] = 'stdClass'; // @todo generate custom class, based on mapping
		}
		$AutoLoader = $GLOBALS['AutoLoader'];

		foreach ($this->namespaces as $namespace) {
			$class = $namespace.$model;
			if (class_exists($class, false) || $AutoLoader->getFilename($class) !== null) { // Is the class known?
//				@todo class compatibility check (Reflection?)
//				@todo import config from class?
				$config['class'] = $class;
			}
		}
		$this->configs[$model] = $config;
		
	}

	private function toBackend($config) {
		$backend = $this->backends[$config['backend']];
		return $backend;
	}

	private function toConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "' . $model . '" not configured');
	}

	/**
	 * Get the key for the $this->objects array.
	 *
	 * @param mixed $id  An array with the id value(s), the instance or an id (as string)
	 * @param array $config
	 * @return string
	 */
	private function toKey($id, $config) {
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, table: "' . $config['table'] . '" requires: "' . human_implode('", "', $config['id']) . '"');
			}
			$keys = array();
			foreach ($config['id'] as $column) {
				if (isset($id[$column]) == false) {
					throw new \Exception('Field: "' . $column . '" missing from id');
				}
				$keys[$column] = $id[$column];
			}
			return implode('+', $keys);
		} elseif (is_object($id)) {
			$instance = $id;
			$keys = array();
			$idFound = false;
			foreach ($config['id'] as $column) {
				$property = array_search($column, $config['mapping']);
				$keys[$column] = null;
				if (property_exists($instance, $property)) {
					$keys[$column] = $instance->$property;
				}
				if ($keys[$column] === null) {
					$keys[$column] = '__NULL__';
				} else {
					$idFound = true; // Minimaal 1 waarde die niet null is?
				}
			}
			if ($idFound == false) {
				return null;
			}
			// @todo Validate if the id is changed
			return implode('+', $keys);
		} elseif (count($config['id']) == 1) {
			return (string) $id;
		}
		throw new \Exception('Unable to convert the $id to a key');
	}

	private function toData($instance, $mapping) {
		return $data;
	}

	/**
	 * Extract the id from the data
	 *
	 * @param array $data
	 * @param array $config
	 * @return array
	 */
	private function toId($data, $config) {
		$id = array();
		foreach ($config['id'] as $column) {
			if (isset($data[$column]) == false) {
				throw new \Exception('Parameter $data must contain the id field(s)');
			}
			$id[$column] = $data[$column];
		}
		return $id;
	}


}

?>