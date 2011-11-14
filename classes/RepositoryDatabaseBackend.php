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
	 * @param array|string $databases
	 */
	function __construct($databases = array()) {
		$dbLinks = array();
		if (is_string($databases)) { // If options is a string that option is a dbLink
			$dbLinks = array($databases => '');
			$databases = array();
		}
		foreach ($databases as $key => $value) {
			if (is_int($key)) {
				$dbLinks[$value] = '';
			} else {
				$dbLinks[$key] = $value;
			}
		}
		foreach ($dbLinks as $dbLink => $prefix) {
			$this->inspectDatabase($dbLink, $prefix);
		}
	}

	/**
	 * Create model configs based on the tables in the database schema
	 *
	 * @param string $dbLink
	 */
	function inspectDatabase($dbLink = 'default', $prefix = '') {

		// Pass 1: Retrieve and parse schema information
		$db = getDatabase($dbLink);
		$schema = $this->getSchema($db, $prefix);

		foreach ($schema as $tableName => $table) {
			if ($prefix != '' && substr($tableName, 0, strlen($prefix)) == $prefix) {
				$plural = ucfirst(substr($tableName, strlen($prefix))); // Strip prefix
			} else {
				$plural = ucfirst($tableName);
			}
			$config = new ModelConfig($this->modelize($tableName, $prefix), array(
					'plural' => $plural,
					'backendConfig' => $table,
				));
			$config->backendConfig['dbLink'] = $dbLink;
			$config->backendConfig['collection'] = array('columns' => array()); // database collection config.
			$config->id = $table['primaryKeys'];
			foreach ($table['columns'] as $column => $info) {
				$default = @$info['default'];
				$config->defaults[$column] = $default;
				if (empty($info['foreignKeys'])) {
					$property = $this->variablize($column);
					$config->properties[$property] = $column;
				} else {
					if (count($info['foreignKeys']) > 1) {
						notice('Multiple foreign-keys per column not supported');
					}
					$foreignKey = $info['foreignKeys'][0];
					$property = $column;
					if (preg_match('/_id$/i', $property)) {
						$alternativePropertyName = substr($property, 0, -3);
						if (array_key_exists($alternativePropertyName, $table['columns']) == false) {
							$property = $alternativePropertyName;
						}
					}
					if (array_key_exists($property, $table['columns']) && $property != $column) {
//						$alternativePropertyName = lcfirst ($this->modelize ($foreignKey['table'], $prefix));
//						if (array_key_exists ($alternativePropertyName, $table['columns']) == false) {
//							$property = $alternativePropertyName;
//						} else {
//							notice('Unable to use belongsTo["'.$property.'"], an column with the same name exists', array('column' => $info));
//						}
						notice('Unable to use belongsTo["'.$property.'"], an column with the same name exists', array('column' => $info));
					} else {
						unset($config->defaults[$column]);
					}
					$config->belongsTo[$property] = array(
						'reference' => $column, // foreignKey
						'model' => $this->modelize($foreignKey['table'], $prefix),
						'id' => $foreignKey['column'], // primairy key
					);
					$config->defaults[$property] = null;
				}
			}
			$this->configs[$config->name] = $config;
		}
		// Pass 2:
		foreach ($this->configs as $config) {
			$table = $schema[$config->backendConfig['table']];
			foreach ($table['referencedBy'] as $reference) {
				$property = $reference['table'];
				if ($prefix != '' && substr($property, 0, strlen($prefix)) == $prefix) {
					$property = substr($property, strlen($prefix)); // Strip prefix
				}
				$property = $this->variablize($property);
				if (array_key_exists($property, $config->properties)) {
					notice('Unable to use "'.$property.'" for hasMany relation config');
					break;
				}
				$model = $this->modelize($reference['table'], $prefix);
				$belongsToModel = $this->configs[$model];
				foreach ($belongsToModel->belongsTo as $belongsToProperty => $belongsTo) {
					if ($belongsTo['model'] == $config->name && $belongsTo['reference'] == $reference['column']) {
						$config->hasMany[$property] = array(
							'model' => $model,
							'reference' => $belongsToProperty.'->'.$belongsTo['id'],
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
				throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.human_implode('", "', $config['primairyKeys']).'"');
			}
		} elseif (count($config['primaryKeys']) == 1) {
			$id = array($config['primaryKeys'][0] => $id); // convert $id to array notation
		} else {
			throw new \Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.human_implode('", "', $config['primairyKeys']).'"');
		}
		$data = $db->fetchRow('SELECT * FROM '.$db->quoteIdentifier($config['table']).' WHERE '.$this->generateWhere($id, $config));
		if ($data === false) {
			throw new \Exception('Failed to retrieve "'.$this->generateWhere($id, $config).'" from "'.$config['table'].'"');
		}
		return $data;
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
				$changes[] = $db->quoteIdentifier($column).' = '.$this->quote($db, $column, $value);
			}
		}
		if (count($changes) == 0) {
			return $new; // No changes, no query
		}
		$where = $this->generateWhere($old, $config);
		$result = $db->exec('UPDATE '.$db->quoteIdentifier($config['table']).' SET '.implode(', ', $changes).' WHERE '.$where);
		if ($result === false) {
			throw new \Exception('Updating record "'.$where.'"  in "'.$config['table'].'" failed');
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
			$values[] = $this->quote($db, $column, $value);
		}
		$result = $db->exec('INSERT INTO '.$db->quoteIdentifier($config['table']).' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).')');
		if ($result === false) {
			throw new \Exception('Adding record into "'.$config['table'].'" failed');
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
		$where = $this->generateWhere($row, $config);
		$result = $db->exec('DELETE FROM '.$db->quoteIdentifier($config['table']).' WHERE '.$where);
		if ($result === false) {
			throw new \Exception('Deleting record "'.$where.'"  from "'.$config['table'].'" failed');
		}
		if ($db instanceof \PDO) {
			if ($result !== 1) {
				throw new \Exception('Removing "'.$where.'" from "'.$config['table'].'" failed, '.$result.' rows were affected');
			}
		} elseif ($db instanceof \mysqli) {
			if ($db->affected_rows != 1) {
				throw new \Exception('Removing "'.$where.'" from "'.$config['table'].'" failed, '.$db->affected_rows.' rows were affected');
			}
		} else {
			notice('Implement affected_rows for '.get_class($db));
		}
	}

	/**
	 *
	 * @param array $keys  Array containing the primay keys
	 * @param array $config
	 * @return string
	 */
	private function generateWhere($keys, $config) {
		$db = getDatabase($config['dbLink']);
		$where = array();
		foreach ($config['primaryKeys'] as $column) {
			if (isset($keys[$column]) == false) {
				throw new \Exception('Missing key: "'.$column.'"'); // @todo better error
			}
			$where[] = $db->quoteIdentifier($column).' = '.$this->quote($db, $column, $keys[$column]);
		}
		if (count($where) == 0) {
			throw new \Exception('Invalid config, no "primaryKeys" defined');
		}
		return implode(' AND', $where);
	}

	/**
	 * Don't put quotes around number for columns that are assumend to be integers ('id' or ending in '_id')
	 *
	 * @param Database $db
	 * @param string $column
	 * @param mixed $value
	 */
	private function quote($db, $column, $value) {
		if ((is_int($value) || preg_match('/^[123456789]{1}[0-9]*$/', $value)) && ($column == 'id' || substr($column, -3) == '_id')) {
			return $value;
		}
		return $db->quote($value);
	}

	/**
	 * Create a model name from a plural table name.
	 *
	 * @param string $table
	 * @param string $prefix  Database/table prefix
	 * @return string
	 */
	private function modelize($table, $prefix = '') {
		if ($prefix != '' && substr($table, 0, strlen($prefix)) == $prefix) {
			$table = substr($table, strlen($prefix)); // Strip prefix
		}
		return ucfirst(Inflector::singularize($table));
	}

	/**
	 * Returns the  columnname as property notation
	 *
	 * @param string $column
	 * @return string
	 */
	private function variablize($column) {
		// @todo implement camelCase
		return $column;
	}

	/**
	 * Get column and relation information from the database.
	 *
	 * @param Database $db
	 * @param string $prefix  Table prefix
	 * @return array
	 */
	private function getSchema($db, $prefix = '') {
		$driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if ($driver == 'mysql') {
			return $this->getSchemaMySql($db, $prefix);
		} elseif ($driver == 'sqlite') {
			return $this->getSchemaSqlite($db, $prefix);
		} else {
			warning('PDO driver: "'.$driver.'" not supported');
			return false;
		}
	}

	/**
	 * Extract the Database schema from a MySQL database
	 * @param Database $db
	 * @return array  schema definition
	 */
	private function getSchemaMySql($db, $prefix = '') {
		$schema = array();
		if ($prefix != '') {
			$tables = $db->query('SHOW TABLES LIKE '.$db->quote($prefix.'%'));
		} else {
			$tables = $db->query('SHOW TABLES');
		}
		foreach ($tables as $row) {
			$table = current($row);
			$referencedBy = isset($schema[$table]) ? $schema[$table]['referencedBy'] : array();
			$schema[$table] = array(
				'table' => $table,
				'columns' => array(),
				'primaryKeys' => array(),
				'referencedBy' => $referencedBy,
			);
			$config = &$schema[$table];
			$showCreate = $db->fetchRow('SHOW CREATE TABLE '.$table);
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
								while ($part = $parts[$i + 1]) {
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
										case 'NULL';
											$default = null;
											break;
										case 'CURRENT_TIMESTAMP': $default = null;
											break;
										default:
											notice('Unknown default "'.$default.'" in "'.$line.'"');
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
								while ($part = $parts[$i + 1]) {
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

						case 'KEY':
						case 'UNIQUE_KEY';
							break; // Skip

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
//					dump($line);
				}
			}
			unset($config);
		}
		return $schema;
	}

	/**
	 * Extract the Database schema from a Sqlite database
	 * @param Database $dbLink
	 * @return array  schema definition
	 */
	private function getSchemaSqlite($db, $prefix = '') {
		$schema = array();
		$sql = 'SELECT tbl_name FROM sqlite_master WHERE type = "table" AND name != "sqlite_sequence"';
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
