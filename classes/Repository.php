<?php
/**
 * Repository/DataMapper
 * 
 * @package Record
 */
namespace SledgeHammer;
class Repository extends Object {
	
	private $id;
	
	private $namespaces = array('', 'SledgeHammer\\');

	// model => datasource
	private $configs = array();

	// references to 
	private $objects = array();
	

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
		if (preg_match('/^get(.+)$/', $method, $matches)) {
			if (count($arguments) > 1) {
				notice('Too many arguments, expecting 1', $arguments);
			}
			return $this->load($matches[1], $arguments[0]);
		}
		if (preg_match('/^save(.+)$/', $method, $matches)) {
			if (count($arguments) > 1) {
				notice('Too many arguments, expecting 1', $arguments);
			}
			return $this->save($matches[1], $arguments[0]);
		}

		return parent::__call($method, $arguments);
	}
	
	/**
	 * Retrieve an instance from the Repository
	 * 
	 * @param string $model
	 * @param mixed $id  The instance ID
	 * @return instance
	 */
	function load($model, $id) {
		if ($id === null) {
			throw new \Exception('Parameter $id is required');
		}
		$config = $this->getConfig($model);

		$key = $this->toKey($id, $config);
		$instance = @$this->objects[$model][$key]['instance'];
		if ($instance !== null) {
			return $instance;
		}
		$data = $this->loadData($config, $id);
		return $this->create($model, $data);
	}

	/**
	 * Create a instance from existing $data.
	 * This won't store the data. For storing data use $repository->save($instance)
	 * 
	 * @param string $model
	 * @param array/object $data Data from the 
	 * @return instance
	 */
	function create($model, $data) {
		if ($data === null) {
			throw new \Exception('Parameter $data is required');
		}
		$config = $this->getConfig($model);
		$id = $this->toId($data, $config);
		$key = $this->toKey($id, $config);
		
		$instance = @$this->objects[$model][$key]['instance'];
		if ($instance !== null) {
			// @todo validate existing data
			return $instance;
		}

		// Create new instance
		$definition = $config['class'];
		$instance = new $definition();
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
								if (empty($relation['table'])) {
									warning('Unable to determine source for property "'.$property.'"');
									break;
								}
								$relation['model'] = $this->toModel($relation['table']);
								$this->configs[$model]['mapping'][$property]['model'] = $relation['model']; // update config
							}
							$belongsToInstance = @$this->objects[$relation['model']][$belongsToId]['instance'];
							if ($belongsToInstance !== null) {
								$instance->$property = $belongsToInstance;
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
						break;
						
					case 'hasMany':
						if (count($id) != 1) {
							throw new \Exception('Complex keys not (yet) supported for hasMany relations');
						}
						$relationConfig = $this->getConfig($relation['model']);
						$collection = $this->loadCollection($relation['model']);
						$collection->sql = $collection->sql->andWhere($relation['reference'].' = '.current($id));
						$instance->$property = new HasManyPlaceholder(array(
							'repository' => $this->id,
							'collection' => $collection,
							'property' => $property,
							'container' => $instance,
						));
						break;
					
					default:
						throw new \Exception('Invalid mapping type: "'.$relation['type'].'"');
					
				}
			}
		}
		$key = $this->toKey($id, $config);
		$this->objects[$model][$key] = array(
			'instance' => $instance,
			'data' => $data,
		);
		return $instance;
	}
	
	/**
	 *
	 * @param string $model
	 * @return Collection 
	 */
	function loadCollection($model) {
		$config = $this->getConfig($model);
		$config['repository'] = $this->id;
		// @todo support for multiple backends
		$sql = select('*')->from($config['table']);
		$collection = new DatabaseCollection($sql, $config['dbLink']);
		$collection->bind($model, $this->id); 
		return $collection;
	}

	/**
	 * Store the intance
	 * 
	 * @param string $model
	 * @param stdClass $instance 
	 */
	function save($model, $instance) {
		$config = $this->getConfig($model);
		$data = array();
		$collections = array();
		foreach ($config['mapping'] as $property => $relation) {
			if (is_string($relation)) { // direct property to column mapping
				$data[$relation] = $instance->$property;
			} else {
				switch ($relation['type']) {
					
					case 'hasMany':
						$collections[$property] = $instance->$property;
						break;
						
					case 'belongsTo':
						$belongsTo = $instance->$property;
						if (($belongsTo instanceof BelongsToPlaceholder) == false) {
							$this->save($relation['model'], $belongsTo);
						}
						$idProperty = $relation['id'];
						$data[$relation['reference']] = $belongsTo->$idProperty;
						break;
						
					default:
						throw new \Exception('Invalid mapping type: "'.$relation['type'].'"');
					
				}
			}
		}
		$id = $this->toId($data, $config);
		$key = $this->toKey($id, $config);
		$current = @$this->objects[$model][$key];
		if ($current === null) { // New instance?
			$this->addData($config, $data);
			$this->objects[$model][$key] = array(
				'instance' => $instance, 
				'data' => $data
			);
		} else { // Existing instance?
			if ($current['instance'] !== $instance) {
				// @todo ID change detection
				throw new \Exception('The instance is not bound to this Repository');
			}
			$this->updateData($config, $id, $data, $current['data']);
		}		
	}
	
	function getConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "'.$model.'" not configured');
	}
	
	private function loadData($config, $id) {
		// @todo support different backends
		return $this->loadDatabaseRecord($config, $id);
	}
	
	private function updateData($config, $id, $new, $old = null) {
		// @todo support different backends
		$result =  $this->updateDatabaseRecord($config, $id, $new, $old);
		$key = $this->toKey($id, $config);
		$this->objects[$config['model']][$key]['data'] = $new;
		return $result;
	}
	
	private function toPlural($singular) {
		return $singular.'s';
	}
	
	private function toSingular($plural) {
		return preg_replace('/s$/', '', $plural);
	}
	
	private function toModel($table) {
		// @todo implement mapping
		return ucfirst($this->toSingular($table));
	}
	private function toTable($model) {
		// @todo implement mapping
		return lcfirst($this->toPlural($model));
	}
	private function toProperty($column) {
		// @todo implement camelCase
		return $column;
	}
	private function toKey($id, $config) {
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.  human_implode('", "', $config['id']).'"');
			}
			$keys = array();
			foreach ($config['id'] as $column) {
				if (isset($id[$column]) == false) {
					throw new \Exception('Field: "'.$column.'" missing from id');
				}
				$keys[$column] = $id[$column];
			}
			return implode('+', $keys);
		} else {
			return $id;
		}
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
	
	//
	//    Database functions
	//    Should be in backend class
	//
	
	/**
	 * Create model configs based on the tables in the database schema
	 * 
	 * @param string $dbLink 
	 */
	function inspectDatabase($dbLink = 'default') {
		$schema = $this->getSchema($dbLink);
		$AutoLoader = $GLOBALS['AutoLoader'];

		foreach ($schema as $tableName => $table) {
			$model = $this->toModel($tableName);
			
			$config = array(
				'model' => $model,
//				'plural' => $this->toPlural($model),
				'class' => 'stdClass', // @todo Generate data classses based on \SledgeHammer\Object
				'dbLink' => $dbLink,
				'table' => $tableName,
				'id' => null,
				'mapping' => array(),
			);
			$config['id'] = $table['primaryKeys'];
			foreach ($this->namespaces as $namespace) {
				$class = $namespace.$model;
				if (class_exists($class, false) || $AutoLoader->getFilename($class) !== null) { // Is the class known?
	//				$reflection = new \ReflectionClass($definition);
	//				dump($reflection);
	//				@todo class compatibility check 
	//				@todo import config
					$config['class'] = $class;
				}
			}
			foreach ($table['columns'] as $column => $info) {
				if (isset($info['foreignKeys'])) {
					if (count($info['foreignKeys']) > 1) {
						notice('Multiple foreign-keys per column not supported');
					}
					$foreignKey = $info['foreignKeys'][0];
					$property = $column;
					if (substr($property, -3) == '_id') {
						$property = substr($property, 0, -3);
						if (array_key_exists($property, $table['columns'])) {
							notice('Unable to use "'.$property.'" for relation config');
							$property .= '_id';
						}
					}				
					$config['mapping'][$property] = array(
						'type' => 'belongsTo',
						'reference' => $column,
						'table' => $foreignKey['table'],
						'id' => $foreignKey['column'],
					);
				} else {
					$config['mapping'][$this->toProperty($column)] = $column;
				}
			}
			foreach ($table['referencedBy'] as $reference) {
				$property = $this->toProperty($reference['table']);
				if (array_key_exists($property, $table['columns'])) {
					notice('Unable to use "'.$property.'" for relation config');
					break;
				}
				$config['mapping'][$property] = array(
					'type' => 'hasMany',
					'model' => $this->toModel($reference['table']),
					'reference' => $reference['column'],
				);
			}		
			$this->configs[$model] = $config;
		}
	}
	
	private function getSchema($dbLink) {
		$schema = array();

		$db = getDatabase($dbLink);
		$result = $db->query('SHOW TABLES');
		foreach ($result as $row) {
			$table = current($row);
			
			$schema[$table] = array(
				'table' => $table,
				'columns' => array(),
				'primaryKeys' =>array(),
				'referencedBy' => array(),
			);
			$config = &$schema[$table];
			/*
			$fields = $db->query('DESCRIBE '.$table, 'Field');

			foreach ($fields as $column => $field) {
				if ($field['Key'] == 'PRI') {
					$config['primary_keys'][] = $column;
				}
				$config['columns'][] = $column;
				if ($db->server_version < 50100 && $field['Default'] === '') { // Vanaf MySQL 5.1 is de Default waarde NULL ipv "" als er geen default is opgegeven
					$config['default_values'][$column] = NULL; // Corrigeer de defaultwaarde "" naar NULL
				} else {
					$config['schema']['default_values'][$column] = $field['Default'];
				} 
			}*/
			$showCreate = $db->fetch_row('SHOW CREATE TABLE '.$table);
			$createSyntax = $showCreate['Create Table'];
			$lines = explode("\n", $createSyntax);

			unset($lines[0]); // CREATE TABLE * (
			unset($lines[count($lines)]); // ) ENGINE=*
			foreach ($lines as $line) {
				$line = preg_replace('/^  |,$/', '', $line); // "  " & "," weghalen
				$line = str_replace('NOT ', 'NOT_', $line);
				$line = str_replace(' KEY', '_KEY', $line);
				$parts = explode(' ', $line);
				if (substr($parts[0], 0, 1) == '`') { // Column description
					$column = substr($parts[0], 1, -1);
					$config['columns'][$column] = array(
						'type' => $parts[1],
					);
					$columnConfig = &$config['columns'][$column];
					unset($parts[0], $parts[1]);
					foreach ($parts as $index => $part) {
						switch ($part) {
							case 'NOT_NULL';
								$columnConfig['null'] = false;
								break;
							
							case 'AUTO_INCREMENT': break;
							
							default:
								notice('Unknown part "'.$part.'" in "'.$line.'"');
								dump($parts);
								break;
						}
					}
				} else {
					$parts = explode(' ', str_replace('`', '', $line)); // big assumption. @todo realy parse the create string 
					switch ($parts[0]) {
						case 'PRIMARY_KEY':
							$config['primaryKeys'] = explode(',', substr($parts[1], 1, -1));
							break;
						
						case 'KEY': break;
							
						case 'CONSTRAINT':
							if ($parts[2] != 'FOREIGN_KEY') {
								notice('Unknown constraint: "'.$line.'"');
								break;
							}
							if ($parts[4] != 'REFERENCES') {
								notice('Unknown foreign key: "'.$line.'"');
								break;
							}
							$column = substr($parts[3], 1, -1);
							$foreignTable = $parts[5];
							$foreignColumn = substr($parts[6], 1, -1);
							$config['columns'][$column]['foreignKeys'][] = array(
								'table' => $foreignTable,
								'column' => $foreignColumn
							);
							$schema[$foreignTable]['referencedBy'][] = array('table' => $table, 'column' => $column);
							break;
						
						default:
							notice('Unknown metadata "'.$parts[0].'" in "'.$line.'"');
							dump($parts);
							break;
					}
//										dump($line);

				}
			}
			unset($config);
		}
		return $schema;
	}
	
	/**
	 * Load the record from the db
	 * 
	 * @param mixed $id
	 * @return array 
	 */
	private function loadDatabaseRecord($config, $id) {
		$db = getDatabase($config['dbLink']);
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.  human_implode('", "', $config['id']).'"');
			}
		} elseif (count($config['id']) == 1) {
			$id = array($config['id'][0] => $id); // convert $id to array notation
		} else {
			throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.  human_implode('", "', $config['id']).'"');
		}
		$sql = select('*')->from($config['table']);
		$sql->where = array('operator' => 'AND');

		foreach ($config['id'] as $key) {
			if (isset($id[$key]) == false) {
				throw new \Exception('Missing key: "'.$key.'"'); // todo better error
			}
			$sql->where[] = $db->quoteIdentifier($key) .' = '.$db->quote($id[$key]);
		}

		return $db->fetch_row($sql);
	}
	
	private function updateDatabaseRecord($config, $id, $new, $old) {
		$db = getDatabase($config['dbLink']);
		$changes = array();
		foreach ($new as $column => $value) {
			if ($value !== $old[$column]) { // is the value changed?
				$changes[] = $db->quoteIdentifier($column).' = '.$db->quote($value);
			}
		}
		if (count($changes) == 0) {
			return; // No changes, no query
		} 
		$id = $this->toId($old, $config);
		$where = array();
		foreach ($id as $column => $value) {
			$where[] = $db->quoteIdentifier($column).' = '.$db->quote($value);
		}
		if (count($where) == 0) {
			throw new \Exception('Invalid id');
		}
		$sql = 'UPDATE '.$db->quoteIdentifier($config['table']).' SET '.implode(', ', $changes).' WHERE '.  implode(' AND ', $where);
		$result = $db->query($sql);
		if ($result == false) {
			throw new \Exception('Updating record "'.  implode(' + ', $id).'" failed');;
		}
	}
}
?>