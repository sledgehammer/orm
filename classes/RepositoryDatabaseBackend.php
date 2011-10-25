<?php
/**
 * Repository backend for database records
 * @todo Validate datatypes before retrievin or removing records, because '12a' will be sillently truncated by mysql to 12
 *
 * @package Record
 */
namespace SledgeHammer;
class RepositoryDatabaseBackend extends RepositoryBackend {

	public $identifier = 'database';
	/**
	 * @var array|ModelConfig
	 */
	public $configs = array();

	/**
	 *
	 * @param array|string $dbLinks
	 */
	function __construct($options = array()) {
		$dbLinks = array();
		if (is_string($options)) { // If options is a string that option is a dbLink
			$dbLinks = array($options);
			$options = array();
		}
		foreach ($options as $key => $option) {
			if (is_int($key)) {
				$dbLinks[] = $option;
			} else {
				$this->$key = $option;
			}

		}
		foreach ($dbLinks as $dbLink) {
			$this->inspectDatabase($dbLink);
		}
	}
	/**
	 * Create model configs based on the tables in the database schema
	 *
	 * @param string $dbLink
	 */
	function inspectDatabase($dbLink = 'default', $prefix = '') {

		// Pass 1: Retrieve and parse schema information
		$schema = $this->getSchema($dbLink, $prefix);

		foreach ($schema as $tableName => $table) {
			$config = new ModelConfig($this->toModel($tableName, $prefix), array(
//				'plural' => $this->toPlural($model),
				'backendConfig' => $table,
			));
			$config->backendConfig['dbLink'] = $dbLink;
			$config->backendConfig['collection'] = array('columns' => array()); // database collection config.
			$config->id = $table['primaryKeys'];
			foreach ($table['columns'] as $column => $info) {
				$default = @$info['default'];
				$config->defaults[$column] = $default;
				if (empty($info['foreignKeys'])) {
					$property = $this->toProperty($column);
					$config->properties[$property] = $column;
				} else {
					if (count($info['foreignKeys']) > 1) {
						notice('Multiple foreign-keys per column not supported');
					}
					$foreignKey = $info['foreignKeys'][0];
					$property = $column;
					if (substr($property, -3) == '_id') {
						$property = substr($property, 0, -3);
						if (array_key_exists($property, $table['columns'])) {
							notice('Unable to use "' . $property . '" for relation config');
							$property .= '_id';
						} else {
							unset($config->defaults[$column]);
						}
					}
					$config->belongsTo[$property] = array(
						'reference' => $column, // foreignKey
						'model' => $this->toModel($foreignKey['table'], $prefix),
						'id' => $foreignKey['column'], // primairy key
					);
					$config->collectionMapping[$property.'->'.$foreignKey['column']] = $column;
					$config->collectionMapping[$property.'.'.$foreignKey['column']] = $column;
					$config->defaults[$property] = null;
				}
			}
			$this->configs[$config->name] = $config;
		}
		// Pass 2:
		foreach ($this->configs as $config) {
			$table = $schema[$config->backendConfig['table']];
			foreach ($table['referencedBy'] as $reference) {
				$property = $this->toProperty($reference['table']);
				if (array_key_exists($property, $config->properties)) {
					notice('Unable to use "' . $property . '" for hasMany relation config');
					break;
				}
				$model = $this->toModel($reference['table']);
				$belongsToModel = $this->configs[$model];
				foreach ($belongsToModel->belongsTo as $belongsToProperty => $belongsTo) {
					if ($belongsTo['model'] == $config->name && $belongsTo['reference'] == $reference['column']) {
						$config->hasMany[$property] = array(
							'model' => $model,
							'property' => $belongsToProperty,
							'id' => $belongsTo['id'], // reverse map?
						);
						$config->defaults[$property] = array();
						break;
					}
				}
			}
		}

	}

	/**
	 * Load the record from the db
	 *
	 * @param mixed $id
	 * @param array $config
	 * @return array
	 */
	function get($id, $config) {
		$db = getDatabase($config['dbLink']);
		if (is_array($id)) {
			if (count($config['primaryKeys']) != count($id)) {
				throw new \Exception('Incomplete id, table: "' . $config['table'] . '" requires: "' . human_implode('", "', $config['primairyKeys']) . '"');
			}
		} elseif (count($config['primaryKeys']) == 1) {
			$id = array($config['primaryKeys'][0] => $id); // convert $id to array notation
		} else {
			throw new \Exception('Incomplete id, table: "' . $config['table'] . '" requires: "' . human_implode('", "', $config['primairyKeys']) . '"');
		}
		$sql = select('*')->from($config['table']);
		$sql->where = array('operator' => 'AND');

		foreach ($config['primaryKeys'] as $key) {
			if (isset($id[$key]) == false) {
				throw new \Exception('Missing key: "' . $key . '"'); // todo better error
			}
			$sql->where[] = $db->quoteIdentifier($key) . ' = ' . $db->quote($id[$key]);
		}
		return $db->fetchRow($sql);
	}

	/**
	 *
	 * @param array $config
	 * @return DatabaseCollection
	 */
	function all($config) {
		$sql = select('*')->from($config['table']);
		return new DatabaseCollection($sql, $config['dbLink']);
	}

	/**
	 *
	 * @param array $new
	 * @param array $old
	 * @param array $config
	 * @return array
	 */
	function update($new, $old, $config) {
		$db = getDatabase($config['dbLink']);
		$changes = array();
		foreach ($new as $column => $value) {
			if ($value !== $old[$column]) { // is the value changed?
				$changes[] = $db->quoteIdentifier($column) . ' = ' . $db->quote($value);
			}
		}
		if (count($changes) == 0) {
			return $new; // No changes, no query
		}
		$where = array();

		foreach ($config['primaryKeys'] as $column) {
			if (isset($old[$column]) == false) {
				throw new \Exception('Parameter $old must contain the id field(s)');
			}
			$where[] = $db->quoteIdentifier($column) . ' = ' . $db->quote($old[$column]);
		}
		if (count($where) == 0) {
			throw new \Exception('Invalid id');
		}
		$sql = 'UPDATE ' . $db->quoteIdentifier($config['table']) . ' SET ' . implode(', ', $changes) . ' WHERE ' . implode(' AND ', $where);
		$result = $this->execute($sql, $config['dbLink']);
		if ($result == false) {
			throw new \Exception('Updating record "' . implode(' + ', $id) . '" failed');
		}
		return $new;
	}

	/**
	 * INSERT record INTO the database.
	 *
	 * @param array $data
	 * @param array $config
	 * @return array
	 */
	function add($data, $config) {
		$db = getDatabase($config['dbLink']);
		$columns = array();
		$values = array();
		foreach ($data as $column => $value) {
			if ($value === value($config['columns'][$column]['default'])) {
				continue;
			}
			$columns[] = $db->quoteIdentifier($column);
			$values[] = $db->quote($value);
		}
		$sql = 'INSERT INTO ' . $db->quoteIdentifier($config['table']) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
		$result = $this->execute($sql, $config['dbLink']);
		if ($result == false) {
			throw new \Exception('Adding record "' . implode(' + ', $id) . '" failed');
		}
		if (count($config['primaryKeys']) == 1) {
			$idColumn = $config['primaryKeys'][0];
			if ($data[$idColumn] === null) {
				if ($db instanceof \PDO) {
					$data[$idColumn] = $db->lastInsertId();
				} elseif ($db instanceof \mysqli) {
					$data[$idColumn] = $db->insert_id;
				} else {
					notice('Implement insert_id for '.get_class($db));
				}
			}
		}
		return $data;
	}

	/**
	 *
	 * @param array $row  The data
	 * @param array $config
	 */
	function delete($row, $config) {
		$db = getDatabase($config['dbLink']);
		$where = array();
		$id = array();
		foreach ($config['primaryKeys'] as $column) {
			$id[$column] = $row[$column];
			$where[] = $db->quoteIdentifier($column) . ' = ' . $db->quote($row[$column]);
		}
		if (count($where) == 0) {
			throw new \Exception('Invalid id');
		}
		$sql = 'DELETE FROM ' . $db->quoteIdentifier($config['table']) . ' WHERE ' . implode(' AND ', $where);
		$result = $this->execute($sql, $config['dbLink']);
		if ($result == false) {
			throw new \Exception('Deleting record "' . implode(' + ', $id) . '" failed');
		}
		if ($db instanceof \PDO) {
			if ($result !== 1) {
				throw new \Exception('Removing "'.implode('-', $id).'" from "'.$config['table'].' failed, '.$result.' rows were affected');
			}
		} elseif ($db instanceof \mysqli) {
			if ($db->affected_rows != 1) {
				throw new \Exception('Removing "'.implode('-', $id).'" from "'.$config['table'].' failed, '.$db->affected_rows.' rows were affected');
			}
		} else {
			notice('Implement affected_rows for '.get_class($db));
		}
	}

	/**
	 *
	 * @param type $sql
	 * @param string $dbLink
	 * @throws \PDOException
	 * @return void
	 */
	private function execute($sql, $dbLink) {
		$db = getDatabase($dbLink);
		$errmode = $db->getAttribute(\PDO::ATTR_ERRMODE);
		$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		try {
			$result = $db->exec($sql);
			$db->setAttribute(\PDO::ATTR_ERRMODE, $errmode);
			return $result;
		} catch (\Exception $e) {
			$db->setAttribute(\PDO::ATTR_ERRMODE, $errmode);
			throw $e;
		}
	}

	private function toModel($table, $prefix = '') {
		if ($prefix != '' && substr($table, 0, strlen($prefix)) == $prefix) {
			$table = substr($table, strlen($prefix));
		}
		// @todo implement mapping
		return ucfirst($this->toSingular($table));
	}

	private function toPlural($singular) {
		return $singular . 's';
	}

	private function toSingular($plural) {
		return preg_replace('/s$/', '', $plural);
	}

	private function toTable($model) {
		// @todo implement mapping
		return lcfirst($this->toPlural($model));
	}

	private function toProperty($column) {
		// @todo implement camelCase
		return $column;
	}

	private function getSchema($dbLink, $prefix = '') {

		$db = getDatabase($dbLink);
		$driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if ($driver == 'mysql') {
			return $this->getSchemaMySql($dbLink, $prefix);
		} elseif($driver == 'sqlite') {
			return $this->getSchemaSqlite($dbLink, $prefix);

		} else {
			warning('PDO driver: "'.$driver.'" not supported');
			return false;
		}
	}

	/**
	 * Extract the Database schema from a MySQL database
	 * @param string $dbLink
	 * @return array  schema definition
	 */
	private function getSchemaMySql($dbLink, $prefix = '') {
		$db = getDatabase($dbLink);
		$schema = array();
		if ($prefix != '') {
			$tables = $db->query('SHOW TABLES LIKE '.$db->quote($prefix.'%'));
		} else {
			$tables = $db->query('SHOW TABLES');
		}
		foreach ($tables as $row) {
			$table = current($row);

			$schema[$table] = array(
				'table' => $table,
				'columns' => array(),
				'primaryKeys' => array(),
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
			  } */
			$showCreate = $db->fetchRow('SHOW CREATE TABLE ' . $table);
			$createSyntax = $showCreate['Create Table'];
			$lines = explode("\n", $createSyntax);

			unset($lines[0]); // CREATE TABLE * (
			unset($lines[count($lines)]); // ) ENGINE=*
			foreach ($lines as $line) {
				$line = preg_replace('/^  |,$/', '', $line); // "  " & "," weghalen
				$line = str_replace('NOT ', 'NOT_', $line);
				$line = str_replace(' KEY', '_KEY', $line);
				$line = str_replace('CHARACTER SET', 'CHARACTER_SET', $line);
				$parts = explode(' ', $line);
				if (substr($parts[0], 0, 1) == '`') { // Column description
					$column = substr($parts[0], 1, -1);
					$config['columns'][$column] = array(
						'type' => $parts[1],
					);
					$columnConfig = &$config['columns'][$column];
					//unset($parts[0], $parts[1]);
					//foreach ($parts as $index => $part) {
					for ($i = 2; $i < count($parts); $i++) {
						$part = $parts[$i];

						switch ($part) {
							case 'NOT_NULL';
								$columnConfig['null'] = false;
								break;

							case 'AUTO_INCREMENT': break;

							case 'DEFAULT':
								$default = '';
								while($part = $parts[$i + 1]) {
									$i++;
									$default .= $part;
									if (substr($default, 0, 1) != "'") { // Not a quoted string value?
										break; // end for loop
									}
									if (substr($default, -1) == "'") { // End of quoted string?
										break; // end for loop
									}
									$default .= ' ';
								}
								if (substr($default, 0, 1) == "'") {
									$config['columns'][$column]['default'] = substr($default, 1, -1); // remove quotes
								} else {
									switch ($default) {
										case 'NULL'; $default = null; break;
										case 'CURRENT_TIMESTAMP': $default = null; break;
										default:
											notice('Unknown default "' . $default . '" in "' . $line . '"');
											break;
									}
									$config['columns'][$column]['default'] = $default;
								}
								break;

							case 'unsigned':
								$config['columns'][$column]['type'] = 'unsigned '.$config['columns'][$column]['type'];
								break;

							case 'COMMENT':
								$comment = '';
								while($part = $parts[$i + 1]) {
									$i++;
									$comment .= $part;
									if (substr($default, 0, 1) != "'") { // Not a quoted string value?
										break; // end for loop
									}
									if (substr($default, -1) == "'") { // End of quoted string?
										break; // end for loop
									}
									$comment .= ' ';
								}
								$config['columns'][$column]['comment'] = $comment;
								break;

							case 'CHARACTER_SET':
								$i++; // ignore value
								break;

							default:
								notice('Unknown part "' . $part . '" in "' . $line . '"');
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

						case 'KEY':
						case 'UNIQUE_KEY';
							break; // Skip

						case 'CONSTRAINT':
							if ($parts[2] != 'FOREIGN_KEY') {
								notice('Unknown constraint: "' . $line . '"');
								break;
							}
							if ($parts[4] != 'REFERENCES') {
								notice('Unknown foreign key: "' . $line . '"');
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
							notice('Unknown metadata "' . $parts[0] . '" in "' . $line . '"');
							dump($parts);
							break;
					}
//					dump($line);
				}
			}
			unset($config);
		}
		return $schema;
	}

	/**
	 * Extract the Database schema from a Sqlite database
	 * @param string $dbLink
	 * @return array  schema definition
	 */
	private function getSchemaSqlite($dbLink, $prefix = '') {
		$db = getDatabase($dbLink);
		$schema = array();
		$sql ='SELECT tbl_name FROM sqlite_master WHERE type = "table" AND name != "sqlite_sequence"';
		if ($prefix != '') {
			$sql .= ' tbl_name LIKE '.$db->quote($prefix.'%');
		}
		$tables = $db->query($sql)->fetchAll();
		// Pass 1: columns
		foreach ($tables as $row) {
			$table = $row['tbl_name'];

			$schema[$table] = array(
				'table' => $table,
				'columns' => array(),
				'primaryKeys' => array(),
			);
			$config = &$schema[$table];
			if (array_key_exists('referencedBy', $config) == false) {
				$config['referencedBy'] = array();
			}

			$columns = $db->query('PRAGMA table_info('.$table.')');
			foreach ($columns as $column) {
				$name = $column['name'];
				$config['columns'][$name] = array(
					'type' => $column['type'],
					'null' => ($column['notnull'] == '0'),
				);
				if ($column['dflt_value'] !== null) {
					$config['columns'][$name]['default'] = $column['dflt_value'];
				}

				if ($column['pk']) {
					$config['primaryKeys'][] = $name;
				}
			}
		}
		// Pass 2: relations
		foreach ($tables as $row) {
			$table = $row['tbl_name'];
			$config = &$schema[$table];

			$foreignKeys = $db->query('PRAGMA foreign_key_list('.$table.')');
			foreach ($foreignKeys as $key) {
				$schema[$table]['columns'][$key['from']]['foreignKeys'][] = array(
					'table' => $key['table'],
					'column' => $key['to'],
				);
				$schema[$key['table']]['referencedBy'][] = array(
					'table' => $table,
					'column' => $key['from'],
				);
			}
			unset($config);
		}
		return $schema;
	}
}

?>
