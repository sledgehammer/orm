<?php
/**
 * Repository/DataSource
 */
namespace SledgeHammer;
class Repository extends Object {
	
	private $id;
	
	private $namespaces = array('', 'SledgeHammer\\');

	private $configs = array();

	private $objects = array();
	
	private $tables = array();
	private $dbLink;


	function __construct($dbLink = 'default') {
		$this->id = uniqid(__CLASS__);
		$this->dbLink = $dbLink;
	}
	
	function __call($method, $arguments) {
		if (preg_match('/^get(.*)$/', $method, $matches)) {
			if (count($arguments) > 1) {
				notice('Too many arguments, expecting 1', $arguments);
			}
			return $this->loadInstance($matches[1], $arguments[0]);
		}
		dump($method);
		error('todo');

	}
	
	private function loadInstance($class, $id) {
		$config = $this->getConfig($class);
		$record = $this->loadRecord($config['dbLink'], $config['table'], $id);
		$definition = $config['class'];
		$instance = new $definition();

		if (count($config['belongsTo']) > 0) {
			foreach ($config['belongsTo'] as $property => $belongsToConfig) {
				$foreignKey = $belongsToConfig['foreignKey'];
				if (isset($record[$foreignKey])) {
					// @todo lazy loading
					if (isset($belongsToConfig['class'])) {
						$belongsToClass = $belongsToConfig['class'];
					} else {
						$belongsToClass = $this->toClass($belongsToConfig['table']);
					}
					$record[$property] = $this->loadInstance($belongsToClass, $record[$foreignKey]);
					unset($record[$foreignKey]);
				} else {
					notice('todo belongs to');

				}
			}
		}
		if (count($config['hasMany']) > 0) {
			foreach ($config['hasMany'] as $propery => $config) {
				//			@todo hasMany;
				$record[$propery] = array('TODO','INPLEMENT', 'hasMany');
			}
		}
		set_object_vars($instance, $record);

		return $instance;
	}
	
	/**
	 * Load the record from the db
	 * 
	 * @param string $dbLink
	 * @param string $table
	 * @param mixed $id
	 * @return array 
	 */
	private function loadRecord($dbLink, $table, $id) {
		$tableInfo = $this->tables[$dbLink][$table];
		if (!is_array($id) && count($tableInfo['primaryKeys']) == 1) {
			$id = array(
				$tableInfo['primaryKeys'][0] => $id,
			);
		} else {
			// @todo check if columns are the ids
		}
		$db = getDatabase($dbLink);
		$sql = new SQL();
		$sql->select('*')->from($table);
		foreach ($id as $column => $value) {
			$sql->where($db->quoteIdentifier($column) .' = '.$db->quote($value));
		}
		return $db->fetch_row($sql);
		
	}
	
	private function getConfig($class) {
		$config = @$this->configs[$class];
		if ($config !== null) {
			return $config;
		}
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
	}
	
	function dbTables($dbLink) {
		$tables = @$this->tables[$dbLink];
		if ($tables !== null) {
			return $tables;
		}
		$this->tables[$dbLink] = array();
		$tables = &$this->tables[$dbLink];

		$db = getDatabase($dbLink);
		$result = $db->query('SHOW TABLES');
		foreach ($result as $row) {
			$table = current($row);
			
			$tables[$table] = array(
				'columns' => array(),
				'belongsTo' => array(),
				'hasMany' => array(),
			);
			$config = &$tables[$table];
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
							$config['belongsTo'][] = array(
								'foreignKey' => $column,
								'table' => $foreignTable,
								'key' =>  $foreignColumn // probably 'id'
							);
							$tables[$foreignTable]['hasMany'][] = array('table' => $table);
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
		return $tables;
	}
	
	private function toPlural($singular) {
		return $singular.'s';
		
	}
	
	private function toSingular($plural) {
		return preg_replace('/s$/', '', $plural);
	}
	
	private function toClass($table) {
		// @todo implement mapping
		return ucfirst($this->toSingular($table));
	}
	private function toTable($class) {
		// @todo implement mapping
		return lcfirst($this->toPlural($class));
	}
}
?>
