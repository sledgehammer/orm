<?php
/**
 * Repository/DataSource
 */
namespace SledgeHammer;
class Repository extends Object {
	
	private $id;
	
	private $namespaces = array('', 'SledgeHammer\\');

	// model => datasource
	private $configs = array();

	private $objects = array();
	

	function __construct() {
		$this->id = uniqid('R');
		$GLOBALS['Repositories'][$this->id] = &$this;
	}
	
	function __call($method, $arguments) {
		if (preg_match('/^get(.*)$/', $method, $matches)) {
			if (count($arguments) > 1) {
				notice('Too many arguments, expecting 1', $arguments);
			}
			return $this->loadInstance($matches[1], $arguments[0]);
		}
		return parent::__call($method, $arguments);
	}
	
	private function loadInstance($model, $id) {
		$config = $this->getConfig($model);
		$record = $this->loadRecord($config, $id);
		$definition = $config['class'];
		$instance = new $definition();
		foreach ($config['mapping'] as $property => $column) {
			if (is_string($column)) {
				$instance->$property = $record[$column];
			} else {
				switch ($column['type']) {
					case 'belongsTo':
						$belongsToId = $record[$column['reference']];
						if ($belongsToId != null) {
							if (empty($column['model'])) {
								if (empty($column['table'])) {
									warning('Unable to determine source for property "'.$property.'"');
									break;
								}
								$column['model'] = $this->toModel($column['table']);
								$this->configs[$model]['mapping'][$property]['model'] = $column['model']; // update config
							}
							$instance->$property = $this->loadInstance($column['model'], $belongsToId);
						}
						break;
					
					default:
						throw new \Exception('Invalid mapping type: "'.$column['type'].'"');
					
				}
			}
		}
		/*
		foreach ($config['belongsTo'] as $property => $belongsTo) {
			$id = $record[$belongsTo['source']];
			if ($id != null) {
				notice('todo belongs to');
							dump($belongsTo);


			}
//			$foreignKey = $belongsToConfig['foreignKey'];
//			if (isset($record[$foreignKey])) {
				// @todo lazy loading
//				if (isset($belongsToConfig['class'])) {
//					$belongsToClass = $belongsToConfig['class'];
//				} else {
//					$belongsToClass = $this->toClass($belongsToConfig['table']);
//				}
//				$record[$property] = $this->loadInstance($belongsToClass, $record[$foreignKey]);
//				unset($record[$foreignKey]);
//			} else {
//			}
		}
//		foreach ($config['hasMany'] as $propery => $config) {
			//			@todo hasMany;
//			$record[$propery] = array('TODO','INPLEMENT', 'hasMany');
//		}
*/
		return $instance;
	}
	
	/**
	 * Load the record from the db
	 * 
	 * @param mixed $id
	 * @return array 
	 */
	private function loadRecord($config, $id) {
		$db = getDatabase($config['dbLink']);
		$sql = new SQL();
		$sql->select('*')->from($config['table']);
		if (is_string($config['id'])) {
			$sql->where($db->quoteIdentifier($config['id']) .' = '.$db->quote($id));
		} else {
			error('not implemented');
		}
		return $db->fetch_row($sql);
		
	}
	
	function getConfig($model) {
		$config = @$this->configs[$model];
		if ($config !== null) {
			return $config;
		}
		throw new \Exception('Model "'.$model.'" not configured');
		/*
		dump($model);
		$this->configs[$class] = array(
			'class' => 'stdClass'
		);
		$config = &$this->configs[$class];
		$AutoLoader = $GLOBALS['AutoLoader'];
		foreach ($this->namespaces as $namespace) {
			$definition = $namespace.$class;
			if (class_exists($definition, false) || $AutoLoader->getFilename($definition) !== null) { // Is the class known?
//				$reflection = new \ReflectionClass($definition);
//				dump($reflection);
//				@todo class compatibility check 
//				@todo import config
				$config['class'] = $definition;
			}
		}
		// @todo Make it "backend/database" angnostic
		if (empty ($config['plural'])) {
			$config['plural'] = $this->toPlural($class);
		}
		if (empty($config['table'])) {
			$config['table'] = $this->toTable($class);
		}
		error('oops');
		if (empty($config['dbLink'])) {
			$config['dbLink'] = $this->dbLink;
		}
		$tables = $this->dbTables($config['dbLink']);
		if (empty($tables[$config['table']])) {
			throw new \Exception('Table "'.$config['table'].'" not found for "'.$class.'"');
		}
		$tableInfo = $tables[$config['table']];
		if (empty($config['hasMany'])) {
			$config['hasMany'] = array();
			foreach ($tableInfo['hasMany'] as $info) {
				$table = $info['table'];
				$config['hasMany'][$table] = array(
					'table' => $table, 
					'dbLink' => $config['dbLink']
				);
			}
		}
		if (empty($config['belongsTo'])) {
			$config['belongsTo'] = array();
			foreach ($tableInfo['belongsTo'] as $info) {
				$table = $info['table'];
				$property = lcfirst($this->toClass($table));
				$config['belongsTo'][$property] = array(
					'dbLink' => $config['dbLink'],
					'table' => $info['table'],
					'foreignKey' => $info['foreignKey'],
				);
			}
		}
		return $config;
		 */
	}
	
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
				'plural' => $this->toPlural($model),
				'class' => 'stdClass', // @todo Generate data classses based on \SledgeHammer\Object
				'dbLink' => $dbLink,
				'table' => $tableName,
				'id' => null,
				'mapping' => array(),
//				'belongsTo' => array(),
//				'hasMany' => array(),
			);
			if (count($table['primaryKeys']) == 1) {
				$config['id'] = $table['primaryKeys'][0];
			} else { // complex key
				$config['id'] = $table['primaryKeys'];
			}
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
}
?>