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

	public $baseClass = 'stdClass';
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
	private $autoComplete;

	function __construct() {
		$this->id = uniqid('R');
		$GLOBALS['Repositories'][$this->id] = $this; // Register this Repository to the Repositories pool.
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
		if (preg_match('/^(get|save|delete|create)(.+)$/', $method, $matches)) {
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
		$data = $this->_getBackend($config->backend)->get($id, $config->backendConfig);
		$indexFromData = $this->resolveIndex($data, $config);
		if ($index != $indexFromData) {
			unset($this->objects[$model][$index]); // cleanup invalid entry
			throw new \Exception('The $id parameter doesn\'t match the retrieved data. '.$index.' != '.$indexFromData);
		}
		$this->objects[$model][$index]['data'] = $data;
		$this->objects[$model][$index]['state'] = 'retrieved';

		$instance = $this->convertToInstance($data, $config, $index);
		$this->objects[$model][$index]['instance'] = $instance;
		if ($preload) {
			foreach ($config->belongsTo as $property => $relation) {
				$value = $instance->$property;
				if ($value instanceof BelongsToPlaceholder) {
					$this->loadAssociation($model, $instance, $property, true);
				}
			}
			foreach ($config->hasMany as $property => $relation) {
				$value = $instance->$property;
				if ($value instanceof HasManyPlaceholder) {
					$this->loadAssociation($model, $instance, $property, true);
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
		$collection = $this->_getBackend($config->backend)->all($config->backendConfig);
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
		$belongsTo = array_value($config->belongsTo, $property);
		if ($belongsTo !== null) {
			$id = $object['data'][$belongsTo['reference']];
			if ($id === null) {
				dump($object);
				throw new \Exception('Now what?');
			}
			$instance->$property = $this->get($belongsTo['model'], $id, $preload);
			return;
		}
		$hasMany = array_value($config->hasMany, $property);
		if ($hasMany !== null) {
			if (count($config->id) != 1) {
				throw new \Exception('Complex keys not (yet) supported for hasMany relations');
			}
			$id = $instance->{$config->id[0]};
			$foreignProperty = $hasMany['property'].'->'.$hasMany['id'];
			$collection = $this->loadCollection($hasMany['model'])->where(array($foreignProperty => $id));
			if (isset($hasMany['collection'])) {
				foreach ($hasMany['collection'] as $collectionProperty => $value) {
					$collection->$collectionProperty = $value;
				}
			}
			$items = $collection->asArray();
			$this->objects[$model][$index]['hadMany'][$property] = $items; // Add a copy for change detection
			$instance->$property = $items;
			return;
		}
		throw new \Exception('No association found for  '.$model.'->'.$property);
	}

	/**
	 * Delete an instance
	 *
	 * @param string $model
	 * @param instance|id $mixed  The instance or id
	 */
	function delete($model, $mixed) {
		$config = $this->_getConfig($model);
		$index = $this->resolveIndex($mixed, $config);
		$object = @$this->objects[$model][$index];
		if ($object === null) {
			if (is_object($mixed)) {
				throw new \Exception('The instance is not bound to this Repository');
			}
			// The parameter is the id
			if (is_array($mixed)) {
				$data = $mixed;
			} else {
				$data = array($config->id[0] => $mixed); // convert the id to array-notation
			}
		} elseif ($object['state'] == 'new') { // The instance issn't stored in the backend and only exists in-memory?
			throw new \Exception('Removing instance failed, the instance issn\'t stored in the backend');
		} else {
			$data = $object['data'];
		}
		$this->_getBackend($config->backend)->delete($data, $config->backendConfig);
		$this->objects[$model][$index]['state'] = 'deleted';
	}

	/**
	 * Create an in-memory instance of the model, ready to be saved.
	 *
	 * @param string $model
	 * @param array $values  Initial contents of the object (optional)
	 * @return object
	 */
	function create($model, $values = array()) {
		$config = $this->_getConfig($model);
		$values = array_merge($config->defaults, $values);
		$index = uniqid('TMP-');
		$class = $config->class;
		$instance = new $class;
		// Apply initial values
		foreach ($values as $property => $value) {
			// @todo Support complex mapping
			$instance->$property = $value;
		}
		$this->objects[$model][$index] = array(
			'state' => 'new',
			'instance' => $instance,
			'data' => null,
		);
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
	 *   'add_unknown_instance' => bool, false: Reject unknown instances. (use $repository->create())
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

		$object = @$this->objects[$model][$index];
		if ($object === null) {
			foreach ($this->created[$config->name] as $createdIndex => $created) {
				if ($instance === $created) {
					$index = $createdIndex;
					$object = $this->objects[$model][$index];
					break;
				}
			}
			// @todo Check if the instance is bound to another $index, aka ID change
			if ($object === null) {
				throw new \Exception('The instance is not bound to this Repository');
			}
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
			if (value($options['ignore_relations']) == false) {
				foreach ($config->belongsTo as $property => $belongsTo) {
					if ($instance->$property !== null && ($instance->$property instanceof BelongsToPlaceholder) == false) {
						$this->save($belongsTo['model'], $instance->$property, $relationSaveOptions);
					}
				}
			}

			// Save instance
			$data = $this->convertToData($object['instance'], $config);
			if ($previousState == 'new') {
				$object['data'] = $this->_getBackend($config->backend)->add($data, $config->backendConfig);
				unset ($this->created[$config->name][$index]);
				unset ($this->objects[$config->name][$index]);
				$changes = array_diff($object['data'], $data);
				if (count($changes) > 0) {
					foreach ($changes as $column => $value) {
						$instance->$column = $value; // @todo reversemap the column to the property
					}
				}
				$index = $this->resolveIndex($instance, $config);
				// @todo check if index already exists?
				$this->objects[$model][$index] = $object;
			} else {
				$this->objects[$model][$index]['data'] = $this->_getBackend($config->backend)->update($data, $object['data'], $config->backendConfig);
			}

			// Save hasMany
			if (value($options['ignore_relations']) == false) {
				foreach ($config->hasMany as $property => $hasMany) {
					if ($instance->$property instanceof HasManyPlaceholder) {
						continue; // No changes (It's not even accessed)
					}
					$collection = $instance->$property;
					if ($collection instanceof \Iterator) {
						$collection = iterator_to_array($collection);
					}
					if ($collection === null) {
						notice('Expecting an array for property "'.$property.'"');
						$collection = array();
					}
					// Determine old situation
					$old = @$this->objects[$model][$index]['hadMany'][$property];
					if ($old === null && value($options['keep_missing_related_instances']) == false) {
						// Delete items that are no longer in the relation
						if ($previousState != 'new' && $old === null && is_array($collection)) { // Is the property replaced, before the placeholder was replaced?
							// Load the previous situation
							$this->loadAssociation($model, $instance, $property);
							$old = $instance->$property;
							$instance->$property = $collection;
						}
					}
					if (isset($hasMany['collection']['valueField'])) {
						if (count(array_diff_assoc($old, $collection)) != 0) {
							warning('Saving changes in complex hasMany relations are not (yet) supported.');
						}
						continue;
					}
					$belongsToProperty = $hasMany['property'];
					foreach ($collection as $item) {
						// Connect the items to the instance
						$item->$belongsToProperty = $instance;
						$this->save($hasMany['model'], $item, $relationSaveOptions);
					}
					if (value($options['keep_missing_related_instances']) == false) {
						// Delete items that are no longer in the relation
						if ($old !== null) {
							if ($collection === null && count($old) > 0) {
								notice('Unexpected type NULL for property "'.$property.'", expecting an array or Iterator');
							}
							foreach ($old as $item) {
								if (array_search($item, $collection, true) === false) {
									$this->delete($hasMany['model'], $item);
								}
							}
						}
					}
					$this->objects[$model][$index]['hadMany'][$property] = $collection;
				}
			}
			$this->objects[$model][$index]['state'] = 'saved';
		} catch (\Exception $e) {
			$this->objects[$model][$index]['state'] = $previousState; // @todo Or is an error state more appropriate?
			throw $e;
		}
	}

	/**
	 *
	 * @param RepositoryBackend $backend
	 */
	function registerBackend($backend) {
		if ($backend->identifier === null) {
			throw new \Exception('RepositoryBackend->idenitifier is required');
		}
		if (isset($this->backends[$backend->identifier])) {
			throw new \Exception('RepositoryBackend "'.$backend->identifier.'" already registered');
		}
		$this->backends[$backend->identifier] = $backend;
		foreach ($backend->configs as $config) {
			if ($config->backend === null) {
				$config->backend = $backend->identifier;
			}
			$this->register($config);
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
		$new = $this->convertToData($instance, $config);
		$object = $this->objects[$model][$index];
		if ($object['state'] == 'new') {
			$old = $config->defaults;
		} else {
			$old = $object['data'];
		}
		$diff = array_diff_assoc($new, $old);
		$changes = array();
		foreach ($diff as $key => $value) {
			if ($object['state'] == 'new') {
				$changes[$key]['next'] = $value;
			} else {
				$changes[$key]['previous'] = $old[$key];
				$changes[$key]['next'] = $value;
			}
		}
		return $changes;
	}

	/**
	 *
	 * @param mixed $data
	 * @param ModelConfig $config
	 * @param string|null $index
	 * @return Object
	 */
	protected function convertToInstance($data, $config, $index = null) {
		$class = $config->class;
		$to = new $class;
		$from = $data;
		if ($index === null) {
			$index = $this->resolveIndex($data, $config);
		} elseif (empty($this->objects[$config->name][$index])) {
			throw new \Exception('Invalid index: "'.$index.'"');		} else {
		}
		// Map the data onto the instance
		foreach ($config->properties as $targetPath => $sourcePath) {
			PropertyPath::set($to, $targetPath, PropertyPath::get($from, $sourcePath));
		}
		foreach ($config->belongsTo as $property => $relation) {
			if (isset($relation['convert'])) {
				$value = $this->convert($relation['model'], PropertyPath::get($from, $relation['convert']));
				PropertyPath::set($to, $property, $value);
			} else {
				$belongsToId = $from[$relation['reference']];
				if ($belongsToId !== null) {
					if (empty($relation['model'])) {
						warning('Unable to determine model for property "'.$property.'"');
					}
					$belongsToIndex = $this->resolveIndex($belongsToId);
					$belongsToInstance = @$this->objects[$relation['model']][$belongsToIndex]['instance'];
					if ($belongsToInstance !== null) {
						$to->$property = $belongsToInstance;
					} else {
						$fields = array(
							$relation['id'] => $belongsToId, // @todo reverse mapping
						);
						$to->$property = new BelongsToPlaceholder(array(
							'repository' => $this->id,
							'fields' => $fields,
							'model' => $config->name,
							'property' => $property,
							'container' => $to,
						));
					}
				}
			}
		}
		foreach ($config->hasMany as $property => $relation) {
			if (isset($relation['convert'])) {
				$collection = new Collection(PropertyPath::get($from, $relation['convert']));
				$collection->bind($relation['model'], $this->id);
				PropertyPath::set($to, $property, $collection);
			} else {
				$to->$property = new HasManyPlaceholder(array(
					'repository' => $this->id,
					'model' => $config->name,
					'property' => $property,
					'container' => $to,
				));
			}
		}
		return $to;
	}

	/**
	 *
	 *
	 * @param stdClass $from  The instance
	 * @param array $to  The raw data
	 * @param ModelConfig $config
	 */
	protected function convertToData($instance, $config) {
		$to = array();
		$from = $instance;
		// Put the belongsTo columns at the beginning of the array
		foreach ($config->belongsTo as $property => $relation) {
			$to[$relation['reference']] = null;  // Dont set the value yet. (could be overwritten with an mapping?)
		}
		// Map to data
		foreach ($config->properties as $property => $relation) {
			if (is_string($relation)) { // direct property to column mapping
				if (property_exists($instance, $property)) {
					$to[$relation] = $from->$property;
				} else {
					throw new \Exception('Invalid mapping type');

				}
			}
		}
		// Map the belongTo to the "*_id" columns.
		foreach ($config->belongsTo as $property => $relation) {
			$belongsTo = $from->$property;
			if ($belongsTo === null) {
				$to[$relation['reference']] = null;
			} else {
				$idProperty = $relation['id']; // @todo reverse mapping
				$to[$relation['reference']] = $from->$property->$idProperty;
			}
		}
		return $to;
	}

	/**
	 * Add an configution for a model
	 *
	 * @param ModelConfig $config
	 */
	protected function register($config) {
		if (isset($this->configs[$config->name])) {
			warning('Overwriting model: "'.$config->name.'"'); // @todo? Allow overwritting models? or throw Exception?
		}
//		$config = clone $config;
		if (empty($config->class)) {
			$config->class = $this->baseClass; // @todo generate custom class, based on mapping
			$AutoLoader = $GLOBALS['AutoLoader'];
			foreach ($this->namespaces as $namespace) {
				$class = $namespace.$config->name;
				if (class_exists($class, false) || $AutoLoader->getFilename($class) !== null) { // Is the class known?
	//				@todo class compatibility check (Reflection?)
	//				@todo import config from class?
					$config->class = $class;
				}
			}
		}
		$this->configs[$config->name] = $config;
		$this->created[$config->name] = array();
		// Generate or update the AutoComplete Helper for the default repository?
		if (ENVIRONMENT == 'development' && isset($GLOBALS['Repositories']['default']) && $GLOBALS['Repositories']['default']->id == $this->id) {
			$autoCompleteFile = TMP_DIR.'AutoComplete/repository.ini';
			if ($this->autoComplete === null) {
				if (file_exists($autoCompleteFile)) {
					$this->autoComplete = parse_ini_file($autoCompleteFile, true);
				} else {
					$this->autoComplete = array();
				}
			}
			// Validate AutoCompleteHelper
			$autoComplete = array(
				'class' => $config->class,
			);
			$dirty = false;
			if (empty($this->autoComplete[$config->name])) {
				$this->autoComplete[$config->name] = $autoComplete;
				$dirty = true;
			} else {
				foreach ($this->autoComplete[$config->name] as $key => $value) {
					if ($autoComplete[$key] != $value) {
						$this->autoComplete[$config->name] = $value;
						$dirty = true;
					}
				}
			}
			if ($dirty) {
				mkdirs(TMP_DIR.'AutoComplete');
				write_ini_file($autoCompleteFile, $this->autoComplete, 'Repository AutoComplete config');
				$this->writeAutoCompleteHelper(TMP_DIR.'AutoComplete/DefaultRepository.php', 'DefaultRepository', 'SledgeHammer\AutoComplete');

			}
		}
	}

	/**
	 *
	 * @param string $backend
	 * @return RepositoryBackend
	 */
	private function _getBackend($backend) {
		$backendObject = @$this->backends[$backend];
		if ($backendObject !== null) {
			return $backendObject;
		}
		throw new \Exception('Backend "'.$backend.'" not registered');
	}

	/**
	 *
	 * @param string $model
	 * @return ModelConfig
	 */
	private function _getConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "'.$model.'" not configured');
	}

	/**
	 * Return the ($this->objects) index
	 *
	 * @param mixed $from  data, instance or an id strig or array
	 * @param ModelConfig $config
	 * @return string
	 */
	private function resolveIndex($from, $config = array()) {
		if ((is_string($from) && $from != '') || is_int($from)) {
			return '{'.$from.'}';
		}
		$key = false;
		if (isset($config->id) && count($config->id) == 1) {
			$key = $config->id[0];
		}
		if (is_array($from)) {
			if (count($from) == 1 && $key !== false) {
				if (isset($from[$key])) {
					return $this->resolveIndex($from[$key]);
				}
				throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
			}
			if (is_array($config->id)) {
				if (count($config->id) == 1) {
					$field = $config->id[0];
					if (isset($from[$key])) {
						return $this->resolveIndex($from[$key]);
					}
					throw new \Exception('Failed to resolve index, missing key: "'.$key.'"');
				}
				$index ='{';
				foreach ($config->id as $field) {
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
				$id = $from->$key; // @todo check $config->properties
				if ($id === null) {
					foreach ($this->created[$config->name] as $index => $created) {
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
	 *
	 * @param string $filename
	 * @param string $class  The classname of the genereted class
	 * @param string $namespace  (optional) The namespace of the generated class
	 */
	function writeAutoCompleteHelper($filename, $class, $namespace = null) {
		$php = "<?php\n";
		$php .= "/**\n";
		$php .= " * ".$class." a generated AutoCompleteHelper\n";
		$php .= " *\n";
		$php .= " * @package Record\n";
		$php .= " */\n";
		if ($namespace !== null) {
			$php .= 'namespace '.$namespace.";\n";
		}
		$php .= 'class '.$class.' extends \\'.get_class($this)." {\n\n";
		foreach ($this->configs as $model => $config) {
			$class = $config->class;
			$instanceVar = '$'.lcfirst($model);
			$php .= "\t/**\n";
			$php .= "\t * Retrieve an ".$model."\n";
			$php .= "\t *\n";
			$php .= "\t * @param mixed \$id  The ".$model." ID\n";
			$php .= "\t * @param bool \$preload  Load relations direct\n";
			$php .= "\t * @return ".$class."\n";
			$php .= "\t */\n";
			$php .= "\tfunction get".$model.'($id, $preload = false) {'."\n";
			$php .= "\t\treturn \$this->get('".$model."', \$id, \$preload);\n";
			$php .= "\t}\n";

			$php .= "\t/**\n";
			$php .= "\t * Store the ".$model."\n";
			$php .= "\t *\n";
			$php .= "\t * @param ".$class.'  The '.$model." to be saved\n";
			$php .= "\t * @param array \$options {\n";
			$php .= "\t *   'ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances,\n";
			$php .= "\t *   'add_unknown_instance' => bool, false: Reject unknown instances. (use \$repository->create())\n";
			$php .= "\t *   'reject_unknown_related_instances' => bool, false: Auto adds unknown instances\n";
			$php .= "\t *   'keep_missing_related_instances' => bool, false: Auto deletes removed instances\n";
			$php .= "\t * }\n";
			$php .= "\t */\n";
			$php .= "\tfunction save".$model.'('.$class.' '.$instanceVar.', $options = array()) {'."\n";
			$php .= "\t\treturn \$this->save('".$model."', ".$instanceVar.", \$options);\n";
			$php .= "\t}\n";

			$php .= "\t/**\n";
			$php .= "\t * Create an in-memory ".$model.", ready to be saved.\n";
			$php .= "\t *\n";
			$php .= "\t * @param array \$values (optional) Initial contents of the object \n";
			$php .= "\t * @return ".$class."\n";
			$php .= "\t */\n";
			$php .= "\tfunction create".$model.'($values = array()) {'."\n";
			$php .= "\t\treturn \$this->create('".$model."', \$values);\n";
			$php .= "\t}\n";

			$php .= "\t/**\n";
			$php .= "\t * Delete the ".$model."\n";
			$php .= "\t *\n";
			$php .= "\t * @param ".$class.'|mixed '.$instanceVar.'  An '.$model.' or the '.$model." ID\n";
			$php .= "\t */\n";
			$php .= "\tfunction delete".$model.'('.$instanceVar.') {'."\n";
			$php .= "\t\treturn \$this->delete('".$model."', ".$instanceVar.");\n";
			$php .= "\t}\n";
		}
		$php .= "}";
		return file_put_contents($filename, $php);
	}
}

?>