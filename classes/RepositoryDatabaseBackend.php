<?php
/**
 * Repository backend for database records
 *
 * @package Record
 */
namespace SledgeHammer;
class RepositoryDatabaseBackend extends RepositoryBackend {

	public $models = array();
	/**
	 *
	 * @param array|string $dbLinks
	 */
	function __construct($dbLinks = array()) {
		if (is_string($dbLinks)) {
			$dbLinks = array($dbLinks);
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
	function inspectDatabase($dbLink = 'default') {

		// Pass 1: Retrieve and parse schema information
		$schema = $this->getSchema($dbLink);

		foreach ($schema as $tableName => $table) {
			$model = $this->toModel($tableName);

			$config = array(
//				'plural' => $this->toPlural($model),
//				'class' => 'stdClass', // @todo Generate data classses based on \SledgeHammer\Object
				'dbLink' => $dbLink,
				'table' => $tableName,
				'id' => null,
				'mapping' => array(),
				'defaults' => array(),
			);
			$config['id'] = $table['primaryKeys'];
			foreach ($table['columns'] as $column => $info) {
				$default = @$info['default'];
				$config['defaults'][$column] = $default;
				if (isset($info['foreignKeys'])) {
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
					notice('Unable to use "' . $property . '" for relation config');
					break;
				}
				$config['mapping'][$property] = array(
					'type' => 'hasMany',
					'model' => $this->toModel($reference['table']),
					'reference' => $reference['column'],
				);
			}
			$this->models[$model] = $config;
		}
		// Pass 2:
		foreach ($this->models as $model => $config) {
			foreach ($config['mapping'] as $property => $relation) {
				if (is_array($relation) && $relation['type'] == 'belongsTo' && empty($relation['model'])) {
					if (empty($relation['table'])) {
						warning('Unable to determine model for property "' . $property . '" in model "'.$model.'"');
						break;
					}
					$this->models[$model]['mapping'][$property]['model'] = $this->toModel($relation['table']); // update config

				}
			}
		}
	}

	public function getModels() {
		return $this->models;
	}

	/**
	 * Load the record from the db
	 *
	 * @param mixed $id
	 * @return array
	 */
	function get($id, $config) {
		$db = getDatabase($config['dbLink']);
		if (is_array($id)) {
			if (count($config['id']) != count($id)) {
				throw new \Exception('Incomplete id, table: "' . $config['table'] . '" requires: "' . human_implode('", "', $config['id']) . '"');
			}
		} elseif (count($config['id']) == 1) {
			$id = array($config['id'][0] => $id); // convert $id to array notation
		} else {
			throw new \Exception('Incomplete id, table: "' . $config['table'] . '" requires: "' . human_implode('", "', $config['id']) . '"');
		}
		$sql = select('*')->from($config['table']);
		$sql->where = array('operator' => 'AND');

		foreach ($config['id'] as $key) {
			if (isset($id[$key]) == false) {
				throw new \Exception('Missing key: "' . $key . '"'); // todo better error
			}
			$sql->where[] = $db->quoteIdentifier($key) . ' = ' . $db->quote($id[$key]);
		}

		return $db->fetch_row($sql);
	}

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

		foreach ($config['id'] as $column) {
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
			if ($value === null) {
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
		if (count($config['id']) == 1) {
			$idColumn = $config['id'][0];
			if ($data[$idColumn] === null) {
				if ($db instanceof \mysqli) {
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
	function remove($row, $config) {
		$db = getDatabase($config['dbLink']);
		$where = array();
		$id = array();
		foreach ($config['id'] as $column) {
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
			;
		}
	}

	private function execute($sql, $dbLink) {
		$db = getDatabase($dbLink);
		$setting = $db->throw_exception_on_error;
		$db->throw_exception_on_error = true;
		try {
			$result = $db->query($sql);
			$db->throw_exception_on_error = $setting;
			return $result;
		} catch (\Exception $e) {
			$db->throw_exception_on_error = $setting;
			throw $e;
		}
	}

	private function toModel($table) {
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

	private function getSchema($dbLink) {
		$schema = array();

		$db = getDatabase($dbLink);
		$result = $db->query('SHOW TABLES');
		foreach ($result as $row) {
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
			$showCreate = $db->fetch_row('SHOW CREATE TABLE ' . $table);
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
									notice('Unknown default "' . $default . '" in "' . $line . '"');
									$config['columns'][$column]['default'] = $default;
								}
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

						case 'KEY': break;

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
}

?>
