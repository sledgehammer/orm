<?php
/**
 * Repository/DataMapper
 * 
 * An API to retrieve and store models from their backends and track their changes.
 * A model is a view on top of the data the backend provides.
 *
 * @package Record
 */
namespace SledgeHammer;

class Repository extends Object {

	protected $id;
	protected $namespaces = array('', 'SledgeHammer\\');

	/**
	 * @var array  registerd models: array(model => config)
	 */
	protected $configs = array();

	/**
	 * @var array  references to instances
	 */
	protected $objects = array();

	/**
	 * @var array  references to instances that are not yet added to the backend
	 */
	protected $created = array();
	/**
	 * @var array registerd backends
	 */
	protected $backends = array();

	function __construct() {
		$this->id = uniqid('R');
		$GLOBALS['Repositories'][$this->id] = &$this;
	}

	/**
	 * Catch methods 
	 * @param string $method
	 * @param array $arguments
	 * @return mixed 
	 */
	function __call($method, $arguments) {
		if (preg_match('/^get(.+)Collection$/', $method, $matches)) {
			if (count($arguments) > 0) {
				notice('Too many arguments, expecting none', $arguments);
			}
			return $this->loadCollection($matches[1]);
		}
		if (preg_match('/^(get|save|remove|add|create)(.+)$/', $method, $matches)) {
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
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($id, $config);
		$object = @$this->objects[$model][$index];
		if ($object !== null) {
			return $object['instance'];
		}
		$this->objects[$model][$index] = array(
			'state' => 'retrieving',
			'instance' => null,
			'data' => null,
		);
		$data = $this->_getBackend($config['backend'])->get($id, $config);
		$this->objects[$model][$index]['data'] = $data;
		$this->objects[$model][$index]['state'] = 'retrieved';
		
		$instance = $this->convertToInstance($data, $config);
		$this->objects[$model][$index]['instance'] = $instance;
		if ($preload) {
			foreach (array('belongsTo', 'hasMany') as $relation) {
				if (empty ($this->objects[$model][$index][$relation])) {
					continue;
				}
				foreach ($this->objects[$model][$index][$relation] as $property => $reference) {
					if ($reference === true) {
						$this->loadAssociation($model, $instance, $property, true);
					}
				}	
			}
		}
		return $instance;
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
	function convert($model, $data, $preload = false) {
		if ($data === null) {
			throw new \Exception('Parameter $data is required');
		}
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($data, $config);

		$object = @$this->objects[$model][$index];
		if ($object !== null) {
			// @todo validate $data against $object['data']
			return $object['instance'];
		}
		$this->objects[$model][$index] = array(
			'state' => 'retrieved',
			'instance' => null,
			'data' => $data,
		);
		$instance = $this->convertToInstance($data, $config);
		$this->objects[$model][$index]['instance'] = $instance;
		if ($preload) {
			warning('Not implemented');
		}
		return $instance;
	}

	/**
	 *
	 * @param string $model
	 * @return Collection
	 */
	function loadCollection($model) {
		$config = $this->_getConfig($model);
		$config['repository'] = $this->id;
		// @todo support for multiple backends
		$sql = select('*')->from($config['table']);
		$collection = new DatabaseCollection($sql, $config['dbLink']);
		$collection->bind($model, $this->id);
		return $collection;
	}

	function loadAssociation($model, $instance, $property, $preload = false) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($instance, $config);
		
		$object = @$this->objects[$model][$index];
		if ($object === null || ($instance !== $object['instance'])) {
			throw new \Exception('Instance not bound to this repository');
		}
		$relation = $config['mapping'][$property];
//		dump($model.'->'.$property);
		switch ($relation['type']) {

			case 'belongsTo':
				$id = $object['data'][$relation['reference']];
				if ($id === null) {
					dump($object);
					throw new \Exception('Now what?');
				}
				$instance->$property = $this->get($relation['model'], $id, $preload);
				$this->objects[$model][$index]['belongsTo'][$property] = $instance->$property;
				break;
			
			case 'hasMany':
				if (count($config['id']) != 1) {
					throw new \Exception('Complex keys not (yet) supported for hasMany relations');
				}
				$id = $instance->{$config['id'][0]};
				$collection = $this->loadCollection($relation['model'])->where(array($relation['reference'] => $id));
				$items = $collection->asArray();
				$this->objects[$model][$index]['hasMany'][$property] = $items; // Add a copy for change detection
				$instance->$property = $items;
				break;
			
			default:
				throw new \Exception('Invalid relation-type: '.$relation['type']);
		}
		
	}

	/**
	 * Remove the instance
	 *
	 * @param string $model
	 * @param instance $instance
	 */
	function remove($model, $instance) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($instance, $config);
		$object = @$this->objects[$model][$index];
		if ($object === null) {
			throw new \Exception('The instance is not bound to this Repository');
		}
//		$data = $this->toData($instance, $config['mapping']);
//		if (serialize($data) != serialize($object['data'])) {
//			throw new \Exception('The instance contains unsaved changes'); // Should we throw this Exception here?
//		}
		// @todo add multiple backends
		$this->_getBackend($config['backend'])->remove($object['data'], $config);
		$this->objects[$model][$index]['state'] = 'removed';
	}

	/**
	 * Store the new instance
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @param $ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances, 
	 */
	function add($model, $instance, $ignoreRelations = false) {
		$options = array(
			'add_unknown_instance' => true,
			'ignore_relations' => $ignoreRelations
		);
				
				

				
		// Save the instance
//		if ($current === null) { // New instance?
//			$this->_getBackend($config)->add($data, $config);
//			$index = $this->resolveIndex($data, $config);
//			if (isset($this->objects[$model][$index])) {
//				warning('Overriding a bound instance');
//			}
//			if ($index === null) { // auto increment?
//				$idColumn = $config['id'][0];
//				$id = $data[$idColumn];
//				$instance->$idColumn = $id;
//			}				
//			$this->objects[$model][$index]['instance'] = $instance;
//			$this->objects[$model][$index]['data'] = $data;
//			$this->objects[$model][$index]['state'] = 'saving';
//		} else { // Existing instance?
		
		throw new \Exception('Reimplement add');

				
		return $this->save($model, $instance, $options);
	}

	/**
	 * 
	 */
	function create($model, $data = array()) {
		$config = $this->_getConfig($model);
		$data = array_merge($config['defaults'], $data);
		$instance = 
		// @todo Special mapping
		$index = uniqid('TMP-');
		$this->objects[$model][$index] = array(
			'state' => 'new',
			'instance' => null,
			'data' => null,
		);
		$instance = $this->convertToInstance($data, $config, $index);
		$this->objects[$model][$index]['instance'] = $instance;
		$this->created[$model][$index] = $instance;
		return $instance;
	}
	/**
	 * Store the instance
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @param array $options
	 *   'ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances, 
	 *   'add_unknown_instance' => bool, false: Reject unknown instances. (use $Repository->add())
	 *   'reject_unknown_related_instances' => bool, false: Auto adds unknown instances
	 *   'keep_missing_related_instances' => bool, false: Auto deletes removed instances
	 * }
	 */
	function save($model, $instance, $options = array()) {
		$relationSaveOptions = $options;
		$relationSaveOptions['add_unknown_instance'] = (value($option['reject_unknown_related_instances']) == false);
		$config = $this->_getConfig($model);
		$data = array();
		$index = null;
		$object = null;
		$index = $this->resolveIndex($instance, $config);
		
//		try {
//			$index = $this->resolveIndex($instance, $config);
//		} catch (\Exception $e) {
//			if (value($options['add_unknown_instance']) == false) {
//				throw $e;
//			}
//			notice('Unable to dermine index, probably a new instance (use Repository->add()) for those', $e->getMessage());
//			throw $e;
//
////			ErrorHandler::handle_exception($e);
////			throw new \Exception('Reimplement add');
//			// @todo Check if the instance is bound to another $index 
//		}
		$object = @$this->objects[$model][$index];
		if ($object === null) {
			// @todo Check if the instance is bound to another $index 
			throw new \Exception('The instance is not bound to this Repository');
		}
		$previousState = $object['state'];
		try {
			if ($object['state'] == 'saving') { // Voorkom oneindige recursion
				return;
			}
			if ($object['instance'] !== $instance) {
				// id/index change-detection
				foreach ($this->objects[$model] as $object) {
					if ($object['instance'] === $instance) {
						throw new \Exception('Change rejected, the index changed from '.$this->resolveIndex($object['data'], $config).' to '.$index);
					}
				}
				throw new \Exception('The instance is not bound to this Repository');
			}
			$this->objects[$model][$index]['state'] = 'saving';

			// Save belongsTo
			if (isset($object['belongsTo']) && value($options['ignore_relations']) == false) {  
				foreach ($object['belongsTo'] as $property => $value) {
					if ($instance->$property !== null && ($instance->$property instanceof BelongsToPlaceholder) == false) {
						$relation = $config['mapping'][$property];
						$this->save($relation['model'], $instance->$property, $relationSaveOptions);
					}
				}
			}

			// Save instance
			$data = $this->convertToData($object['instance'], $config);
			if ($previousState == 'new') {
				$data = $this->_getBackend($config['backend'])->add($data, $config);
			} else {
				$data = $this->_getBackend($config['backend'])->update($data, $object['data'], $config);
			}
			$this->objects[$model][$index]['data'] = $data;
			// @todo remap id or evertything?

			// Save hasMany
			if (isset($object['hasMany']) && value($options['ignore_relations']) == false) {  
				foreach ($object['hasMany'] as $property => $old) {
					if ($instance->$property instanceof HasManyPlaceholder) {
						continue; // No changes (It's not even accessed)
					}
					$relation = $config['mapping'][$property];
					$relationConfig = $this->_getConfig($relation['model']);
					$collection = $instance->$property;
					if ($collection instanceof \Iterator) {
						$collection = iterator_to_array($collection);
					}
					if ($collection === null) {
						notice('Expecting an array for property "'.$property.'"');
						$collection = array();
					}
					foreach ($collection as $item) {
						// Connect the item to this instance
						foreach ($relationConfig['mapping'] as $property2 => $relation2) {
							if ($relation2['type'] == 'belongsTo' && $relation['reference'] == $relation2['reference']) {
								$item->$property2 = $instance;
							}
						}
						$this->save($relation['model'], $item, $relationSaveOptions);
					}
					if (value($options['keep_missing_related_instances']) == false) {
						// Delete items that are no longer in the relation
						if ($old !== null) {
							if ($collection === null && count($old) > 0) {
								notice('Unexpected type NULL for property "'.$property.'", expecting an array or Iterator');
							}
							foreach ($old as $item) {
								if (array_search($item, $collection, true) === false) {
									$this->remove($relation['model'], $item);
								}
							}
						}
					}
				}
			}
			$this->objects[$model][$index]['state'] = 'saved';
		} catch (\Exception $e) {
			$this->objects[$model][$index]['state'] = $previousState; // @todo Or is an error state more appropriate?
			throw $e;
		}
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

	/**
	 * Get the unsaved changes.
	 *
	 * @param string $model
	 * @param stdClass $instance
	 * @return array
	 */
	function diff($model, $instance) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($instance, $config);
		$object = @$this->objects[$model][$index];
		$changes = array();
		foreach ($config['mapping'] as $property => $relation) {
			$value = $instance->$property;
			if ($object !== null) {
				if (is_string($relation)) {
					$previous = $object['data'][$relation];
				} else {
					// @todo
					$previous = $value;
				}
				if ($previous !== $value) {
					$changes[$property]['previous'] = $previous;
				}
			} else {
				$previous = null;
			}
			if ($previous !== $value) {
				$changes[$property]['next'] = $value;
			}
		}
		return $changes;
	}
	
	protected function convertToInstance($data, $config, $index = null) {
		$class = $config['class'];
		$to = new $class;
		$from = $data;
		$model = $config['model'];
		if ($index === null) {
			$index = $this->resolveIndex($data, $config);
		} elseif (empty($this->objects[$model][$index])) {
			throw new \Exception('Invalid index: "'.$index.'"');		} else {
		}
		// Map the data onto the instance
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) {
				$to->$property = $from[$relation];
			} else {
				switch ($relation['type']) {

					case 'belongsTo':
						$belongsToId = $from[$relation['reference']];
						if ($belongsToId === null) {
							$this->objects[$model][$index]['belongsTo'][$property] = null;
						} else {
							$this->objects[$model][$index]['belongsTo'][$property] = true;
							if (empty($relation['model'])) {
								warning('Unable to determine model for property "'.$property.'"');
							}
							$belongsToIndex = $this->resolveIndex($belongsToId);
							$belongsToInstance = @$this->objects[$relation['model']][$belongsToIndex]['instance'];
							if ($belongsToInstance === null) {
								$fields = array(
									$relation['id'] => $belongsToId, // @todo reverse mapping
								);
								$to->$property = new BelongsToPlaceholder(array(
									'repository' => $this->id,
									'fields' => $fields,
									'model' => $config['model'],
									'property' => $property,
									'container' => $to,
								));
							}
							if ($belongsToInstance !== null) {
								$to->$property = $belongsToInstance;
								$this->objects[$model][$index]['belongsTo'][$property] = $belongsToInstance;
							}
						}
						break;

					case 'hasMany':
						$to->$property = new HasManyPlaceholder(array(
							'repository' => $this->id,
							'model' => $config['model'],
							'property' => $property,
							'container' => $to,
						));
						$this->objects[$model][$index]['hasMany'][$property] = true;
						break;

					default:
						throw new \Exception('Invalid mapping type: "'.$relation['type'].'"');
				}
			}
		}
		return $to;
	}
	
	/**
	 * 
	 * 
	 * @param stdClass $from  The instance
	 * @param array $to  The raw data
	 * @param array $config 
	 */
	protected function convertToData($instance, $config) {
		$to = array();
		$from = $instance;
		
		// Map to data 
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) { // direct property to column mapping
				if (property_exists($instance, $property)) {
					$to[$relation] = $from->$property;
				}
			} else {
				switch ($relation['type']) {

					case 'belongsTo':
						$belongsTo = $from->$property;
						if ($belongsTo === null) {
							$to[$relation['reference']] = null;
						} else {
							$idProperty = $relation['id']; // @todo reverse mapping 
							$to[$relation['reference']] = $from->$property->$idProperty;
						}
						break;

					case 'hasMany':
						break;

					default:
						throw new \Exception('Invalid mapping type: "'.$relation['type'].'"');
				}
			}
		}
		return $to;
	}

	/**
	 * Add an configution for a model
	 *  
	 * @param string $model
	 * @param array $config
	 */
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

	private function _getBackend($backend) {
		$backendObject = @$this->backends[$backend];
		if ($backendObject !== null) {
			return $backendObject;
		}
		throw new \Exception('Backend "'.$backend.'" not registered');
	}

	private function _getConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "'.$model.'" not configured');
	}
	
	/**
	 * Get an object from the $this->object array based on id
	 * 
	 * @param array $config
	 * @param mixed $id
	 * @throws Exception when the object is not found
	 * @return array array('instance' => ?, 'data' => ?, 'state' => ?)
	 */
	private function _getObjectByIndex($id) {
		if ($id === null) {
			throw new \Exception('Parameter $id is required');
		}
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, model: "'.$config['model'].'" requires: "'.human_implode('", "', $config['id']).'"');
			}
			$keys = array();
			foreach ($config['id'] as $column) {
				if (isset($id[$column]) == false) {
					throw new \Exception('Field: "'.$column.'" missing from id');
				}
				$keys[$column] = $id[$column];
			}
			$index = implode('+', $keys);
		} elseif (count($config['id']) == 1) {
			$index = (string) $id;
		} else {
			throw new \Exception('Invalid $id'); 
		}
		return @$this->objects[$config['model']][$key];
	}
	
	/**
	 * Return the (objects) index 
	 * 
	 * @param mixed $from
	 * @param mixed $idConfig 
	 */
	private function resolveIndex($from, $config = array()) {	
		if ((is_string($from) && $from != '') || is_int($from)) {
			return '{'.$from.'}';
		}
		$key = false;
		if (isset($config['id']) && count($config['id']) == 1) {
			$key = $config['id'][0];
		}
		if (is_array($from)) {
			if (count($from) == 1 && $key !== false) {
				if (isset($from[$key])) {
					return $this->resolveIndex($from[$key]);
				}
				throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
			}
			if (is_array(value($config['id']))) {
				if (count($config['id']) == 1) {
					$field = $config['id'][0];
					if (isset($from[$key])) {
						return $this->resolveIndex($from[$key]);
					}
					throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
				}
				$index ='{';
				foreach ($config['id'] as $field) {
					if (isset($from[$field])) {
						$value = $from[$field];
						if ((is_string($value) && $value != '') || is_int($value)) {
							$index .= $field.':'.$value;
						} else {
							throw new \Exception('Failed to resolve index, invalid value for: "'.$field.'"');
						}
					} else {
						throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
					}
				}
				$index .= '}';
				return $index;
			}
		}
		if (is_object($from)) {
			if ($key !== false) {
				// @todo check $config['mapping']
				if (value($from->$key) === null) {
					foreach ($this->created[$config['model']] as $index => $created) {
						if ($from === $created) {
							return $index;
						}
					}
					throw new \Exception('Failed to resolve index, missing property: "'.$key.'"');
				}
				return $this->resolveIndex($from->$key);
			}
			throw new \Exception('Not implemented');
		}
		throw new \Exception('Failed to resolve index');
	}


	/**
	 * Get the key for the $this->objects array.
	 *
	 * @param mixed $id  An array with the id value(s), the instance or an id (as string)
	 * @param array $config
	 * @return string
	 */
	private function toKey($id, $config) {
		throw new \Exception('Use resolveIndex()');
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.human_implode('", "', $config['id']).'"');
			}
			$keys = array();
			foreach ($config['id'] as $column) {
				if (isset($id[$column]) == false) {
					throw new \Exception('Field: "'.$column.'" missing from id');
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