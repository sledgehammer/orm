<?php

namespace Sledgehammer\Orm\Backend;

use Exception;
use mysqli;
use PDO;
use Sledgehammer\Core\Database\Connection;
use Sledgehammer\Core\Database\DatabaseCollection;
use Sledgehammer\Orm\Inflector;
use Sledgehammer\Orm\ModelConfig;

/**
 * Repository backend for database records.
 *
 * @todo Validate datatypes before retrieving or removing records, because '12a' will be sillently truncated by mysql to 12
 */
class DatabaseRepositoryBackend extends RepositoryBackend
{
    public $identifier = 'db';

    /**
     * @var string The TTL of the database schema cache.
     */
    public static $cacheTimeout = '20sec';

    /**
     * Prepared statements for get() requests.
     *
     * @var array
     */
    private $preparedStatements = [];

    /**
     * @param array|string $databases
     */
    public function __construct($databases = [])
    {
        $dbLinks = [];
        if (is_string($databases)) { // If options is a string that option is a dbLink
            $this->identifier = $databases.'_db';
            $dbLinks = [$databases => ''];
            $databases = [];
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
     * Create model configs based on the tables in the database schema.
     *
     * @param string $dbLink
     */
    public function inspectDatabase($dbLink = 'default', $tablePrefix = '')
    {
        // Pass 1: Retrieve and parse schema information
        $db = Connection::instance($dbLink);
        if (static::$cacheTimeout === false) {
            $schema = $this->getSchema($db, $tablePrefix);
        } else {
            $cacheIdentifier = $dbLink.' '.$tablePrefix.' ';
            if (count($db->logger->entries) > 0) {
                $cacheIdentifier .= $db->logger->entries[0][0]; // Add the connect statement to the identifier.
            }
            $backend = $this;
            $schema = \Sledgehammer\cache('DatabaseRepositoryBackend['.md5($cacheIdentifier).']', static::$cacheTimeout, function () use ($backend, $db, $tablePrefix) {
                return $backend->getSchema($db, $tablePrefix);
            });
        }
        $models = [];
        $junctions = [];
        foreach ($schema as $tableName => $table) {
            $name = Inflector::modelize($tableName, [
                'prefix' => $tablePrefix,
                'singularizeLast' => true,
            ]);
            $plural = Inflector::modelize($tableName, [
                'prefix' => $tablePrefix,
                'singularizeLast' => false,
            ]);
            $config = new ModelConfig($name, [
                'plural' => $plural,
                'backendConfig' => $table,
            ]);
            $config->backendConfig['dbLink'] = $dbLink;
            $config->backendConfig['collection'] = ['columns' => []]; // database collection config.
            $config->id = $table['primaryKeys'];
            foreach ($table['columns'] as $column => $info) {
                $default = @$info['default'];

                if (empty($info['foreignKeys'])) {
                    $property = Inflector::variablize($column);
                    $config->properties[$column] = $property;
                    $config->defaults[$property] = $default;
                    if (substr($info['type'], -10) === 'tinyint(1)' && $info['null'] === false) {
                        $config->readFilters[$column] = __CLASS__.'::valueToBool';
                        $config->writeFilters[$column] = __CLASS__.'::boolToValue';
                        $config->defaults[$property] = self::valueToBool($config->defaults[$column]);
                    }
                } else {
                    if (count($info['foreignKeys']) > 1) {
                        notice('Multiple foreign-keys per column not supported');
                    }
                    $foreignKey = $info['foreignKeys'][0];
                    $property = $this->stripIdSuffix($column);
                    if ($column !== $property && array_key_exists($property, $table['columns'])) {
                        $property = $column; // restore prefix, to prevent a naming collision.
                    }
                    if (array_key_exists($property, $table['columns']) && $property != $column) {
                        notice('Unable to use belongsTo["'.$property.'"], an column with the same name exists', ['belongsTo' => $info, 'Exising column' => $table['columns'][$property]]);
                    }
                    $foreignModel = Inflector::modelize($foreignKey['table'], [
                        'prefix' => $tablePrefix,
                        'singularizeLast' => true,
                    ]);
                    $config->belongsTo[$property] = [
                        'reference' => $column, // foreignKey
                        'model' => $foreignModel,
                        'id' => $foreignKey['column'], // primary key
                        'default' => $default,
                    ];
                }
            }

            // Detect junction table
            if (count($config->id) === 2) {
                $primaryKeyAreForeignKeys = true;
                foreach ($config->belongsTo as $belongsTo) {
                    if (in_array($belongsTo['reference'], $config->id) === false) {
                        $primaryKeyAreForeignKeys = false;
                        break;
                    }
                }
                if ($primaryKeyAreForeignKeys) {
                    $junctions[$tableName] = $config;
                    $this->junctions[$config->name] = $config;
                    continue;
                }
            }
            $models[$tableName] = $config;
            if (isset($this->configs[$config->name])) {
                notice('Model "'.$config->name.'" (for the "'.$tableName.'" table) is defined in both "'.$this->configs[$config->name]->backendConfig['dbLink'].'" and "'.$dbLink.'" ');
                $suffix = 2;
                while (isset($this->configs[$config->name.'_'.$suffix])) {
                    ++$suffix;
                }
                $this->configs[$config->name.'_'.$suffix] = $config;
            } else {
                $this->configs[$config->name] = $config;
            }
        }
        // Pass 2: hasMany
        foreach ($this->configs as $config) {
            $table = $schema[$config->backendConfig['table']];
            foreach ($table['referencedBy'] as $i => $reference) {
                $property = $reference['table'];
                if ($tablePrefix != '' && substr($property, 0, strlen($tablePrefix)) == $tablePrefix) {
                    $property = substr($property, strlen($tablePrefix)); // Strip prefix
                }
                foreach ($table['referencedBy'] as $j => $otherRef) {
                    if ($i != $j && $otherRef['table'] === $reference['table']) { // The model has relations to the same table.
                        $property = $this->stripIdSuffix($reference['column']).'_'.$property;
                    }
                }
                $property = Inflector::variablize($property);
                if (in_array($property, $config->getPropertyNames())) {
                    notice('Unable to use '.$config->name.'->hasMany['.$property.'] a property with the same name exists');
                    break;
                }
                if (isset($models[$reference['table']])) {
                    // One-to-many relation
                    $belongsToModel = $models[$reference['table']];
                    foreach ($belongsToModel->belongsTo as $belongsToProperty => $belongsTo) {
                        if ($belongsTo['model'] == $config->name && $belongsTo['reference'] == $reference['column']) {
                            $config->hasMany[$property] = [
                                'model' => $belongsToModel->name,
                                'reference' => $reference['column'],
                                'belongsTo' => $belongsToProperty,
                            ];
                            $config->defaults[$property] = [];
                            break;
                        }
                    }
                } elseif (empty($junctions[$reference['table']])) {
                    notice('Missing a model or relation for "'.$reference['table'].'" in the database schema');
                }
            }
        }
        // Pass 3: hasMany junctions
        foreach ($this->configs as $config) {
            $table = $schema[$config->backendConfig['table']];
            foreach ($table['referencedBy'] as $reference) {
                if (isset($junctions[$reference['table']])) {
                    // Many-to-many realtion
                    $junction = $junctions[$reference['table']];
                    $hasMany = [
                        'through' => $junction->name,
                        'fields' => $junction->properties,
                    ];
                    foreach ($junction->belongsTo as $belongsToProperty => $belongsTo) {
                        if ($belongsTo['model'] === $config->name) {
                            $hasMany['reference'] = $belongsTo['reference'];
                        } else {
                            $property = Inflector::pluralize($belongsToProperty);
                            $hasMany['model'] = $belongsTo['model'];
                            $hasMany['id'] = $belongsTo['reference'];
                        }
                    }
                    if (in_array($property, $config->getPropertyNames())) {
                        $property = Inflector::variablize($reference['table']);
                    }
                    if (in_array($property, $config->getPropertyNames())) {
                        notice('Unable to use '.$property.'->hasMany['.$property.'] a property with the same name exists');
                        break;
                    }
                    $config->hasMany[$property] = $hasMany;
                    $config->defaults[$property] = [];
                }
            }
        }
    }

    /**
     * Load the record from the db.
     *
     * @param mixed $id
     * @param array $config
     *
     * @return array
     */
    public function get($id, $config)
    {
        $db = Connection::instance($config['dbLink']);
        if (is_array($id)) {
            if (count($config['primaryKeys']) != count($id)) {
                throw new Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.human_implode('", "', $config['primaryKeys']).'"');
            }
        } elseif (count($config['primaryKeys']) == 1) {
            $id = [$config['primaryKeys'][0] => $id]; // convert $id to array notation
        } else {
            throw new Exception('Incomplete id, table: "'.$config['table'].'" requires: "'.human_implode('", "', $config['primaryKeys']).'"');
        }
        $params = [];
        foreach ($config['primaryKeys'] as $column) {
            if (isset($id[$column]) == false) {
                throw new Exception('Missing key: "'.$column.'"'); // @todo better error
            }
            $params[] = $id[$column];
        }
        if (isset($this->preparedStatements[$config['dbLink']][$config['table']])) {
            $statement = $this->preparedStatements[$config['dbLink']][$config['table']];
        } else {
            $statement = $db->prepare('SELECT * FROM '.$db->quoteIdentifier($config['table']).' WHERE '.$this->generateWhere($id, $config, true));
            $this->preparedStatements[$config['dbLink']][$config['table']] = $statement;
        }
        $success = $statement->execute($params);
        if ($success === false) {
            throw new Exception('Failed to retrieve "'.$this->generateWhere($id, $config).'" from "'.$config['table'].'"');
        }
        $rows = $statement->fetchAll();
        if (count($rows) !== 1) {
            if (count($rows) === 0) {
                throw new Exception('Record "'.$this->generateWhere($id, $config).'" doesn\'t exist in "'.$config['table'].'"');
            }
            throw new Exception('Multiple record found for "'.$this->generateWhere($id, $config).'" in "'.$config['table'].'"');
        }

        return $rows[0];
    }

    /**
     * @param array $config
     *
     * @return DatabaseCollection
     */
    public function all($config)
    {
        $sql = \Sledgehammer\select('*')->from($config['table']);

        return new DatabaseCollection($sql, $config['dbLink']);
    }

    /**
     * @param array $new
     * @param array $old
     * @param array $config
     *
     * @return array
     */
    public function update($new, $old, $config)
    {
        $db = Connection::instance($config['dbLink']);
        $changes = [];
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
            throw new Exception('Updating record "'.$where.'"  in "'.$config['table'].'" failed');
        }

        return $new;
    }

    /**
     * INSERT record INTO the database.
     *
     * @param array $data
     * @param array $config
     *
     * @return array
     */
    public function add($data, $config)
    {
        $db = Connection::instance($config['dbLink']);
        $columns = [];
        $values = [];
        $idColumn = (count($config['primaryKeys']) == 1) ? $config['primaryKeys'][0] : false;
        foreach ($data as $column => $value) {
            if ($column === $idColumn && $value === null) {
                continue;
            }
            if (isset($config['columns'][$column]['default']) && $value === $config['columns'][$column]['default']) {
                continue;
            }
            $columns[] = $db->quoteIdentifier($column);
            $values[] = $this->quote($db, $column, $value);
        }
        $result = $db->exec('INSERT INTO '.$db->quoteIdentifier($config['table']).' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).')');
        if ($result === false) {
            throw new Exception('Adding record into "'.$config['table'].'" failed');
        }
        if ($idColumn && $data[$idColumn] === null) {
            if ($db instanceof PDO) {
                $data[$idColumn] = $db->lastInsertId();
            } elseif ($db instanceof mysqli) {
                $data[$idColumn] = $db->insert_id;
            } else {
                notice('Implement insert_id for '.get_class($db));
            }
        }

        return $data;
    }

    /**
     * @param array $row    The data
     * @param array $config
     */
    public function delete($row, $config)
    {
        $db = Connection::instance($config['dbLink']);
        $where = $this->generateWhere($row, $config);
        $result = $db->exec('DELETE FROM '.$db->quoteIdentifier($config['table']).' WHERE '.$where);
        if ($result === false) {
            throw new Exception('Deleting record "'.$where.'"  from "'.$config['table'].'" failed');
        }
        if ($db instanceof PDO) {
            if ($result !== 1) {
                throw new Exception('Removing "'.$where.'" from "'.$config['table'].'" failed, '.$result.' rows were affected');
            }
        } elseif ($db instanceof mysqli) {
            if ($db->affected_rows != 1) {
                throw new Exception('Removing "'.$where.'" from "'.$config['table'].'" failed, '.$db->affected_rows.' rows were affected');
            }
        } else {
            notice('Implement affected_rows for '.get_class($db));
        }
    }

    /**
     * @param array $keys   Array containing the primay keys
     * @param array $config
     *
     * @return string
     */
    private function generateWhere($keys, $config, $prepare = false)
    {
        $db = Connection::instance($config['dbLink']);
        $where = [];
        foreach ($config['primaryKeys'] as $column) {
            if (isset($keys[$column]) == false) {
                throw new Exception('Missing key: "'.$column.'"'); // @todo better error
            }
            if ($prepare) {
                $where[] = $db->quoteIdentifier($column).' = ?';
            } else {
                $where[] = $db->quoteIdentifier($column).' = '.$this->quote($db, $column, $keys[$column]);
            }
        }
        if (count($where) == 0) {
            throw new Exception('Invalid config, no "primaryKeys" defined');
        }

        return implode(' AND ', $where);
    }

    /**
     * Don't put quotes around number for columns that are assumend to be integers ('id' or ending in '_id').
     *
     * @param Database $db
     * @param string   $column
     * @param mixed    $value
     */
    private function quote($db, $column, $value)
    {
        if ((is_int($value) || preg_match('/^[123456789]{1}[0-9]*$/', $value)) && ($column == 'id' || substr($column, -3) == '_id')) {
            return $value;
        }

        return $db->quote($value);
    }

    /**
     * Get column and relation information from the database.
     *
     * @private
     *
     * @param Database $db
     * @param string   $prefix Table prefix
     *
     * @return array
     */
    public function getSchema($db, $prefix = '')
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver == 'mysql') {
            return $this->getSchemaMySql($db, $prefix);
        }
        if ($driver == 'sqlite') {
            return $this->getSchemaSqlite($db, $prefix);
        }
        warning('PDO driver: "'.$driver.'" not supported');

        return false;
    }

    /**
     * Extract the Database schema from a MySQL database.
     *
     * @param Database $db
     *
     * @return array schema definition
     */
    private function getSchemaMySql($db, $prefix = '')
    {
        $schema = [];
        if ($prefix != '') {
            $tables = $db->query('SHOW TABLES LIKE '.$db->quote($prefix.'%'));
        } else {
            $tables = $db->query('SHOW TABLES');
        }
        foreach ($tables as $row) {
            $table = current($row);
            $referencedBy = isset($schema[$table]) ? $schema[$table]['referencedBy'] : [];
            $schema[$table] = [
                'table' => $table,
                'columns' => [],
                'primaryKeys' => [],
                'referencedBy' => $referencedBy,
            ];
            $config = &$schema[$table];
            $showCreate = $db->fetchRow('SHOW CREATE TABLE '.$db->quoteIdentifier($table));
            $createSyntax = $showCreate['Create Table'];
            $lines = explode("\n", $createSyntax);

            unset($lines[0]); // CREATE TABLE * (
            unset($lines[count($lines)]); // ) ENGINE=*
            foreach ($lines as $line) {
                $line = preg_replace('/^  |,$/', '', $line); // "  " & "," weghalen
                $line = str_replace('NOT ', 'NOT_', $line);
                $line = str_replace(' KEY', '_KEY', $line);
                $line = str_ireplace('CHARACTER SET', 'CHARACTER_SET', $line);
                $line = str_ireplace('ON UPDATE', 'ON_UPDATE', $line);
                $line = str_replace('  ', ' ', $line);
                $parts = explode(' ', $line);
                if (substr($parts[0], 0, 1) == '`') { // Column description
                    $column = substr($parts[0], 1, -1);
                    $config['columns'][$column] = [
                        'type' => $parts[1],
                        'null' => true,
                    ];
                    $columnConfig = &$config['columns'][$column];
                    for ($i = 2; $i < count($parts); ++$i) {
                        $part = $parts[$i];

                        switch (strtoupper($part)) {
                            case 'NOT_NULL':
                                $columnConfig['null'] = false;
                                break;
                            case 'NULL':
                                $columnConfig['null'] = true;
                                break;

                            case 'AUTO_INCREMENT':
                                break;

                            case 'DEFAULT':
                                $default = '';
                                while ($part = $parts[$i + 1]) {
                                    ++$i;
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
                                        case 'NULL':
                                            $default = null;
                                            break;
                                        case 'CURRENT_TIMESTAMP':
                                            $default = null;
                                            break;
                                        default:
                                            notice('Unknown default "'.$default.'" in "'.$line.'"');
                                            break;
                                    }
                                    $config['columns'][$column]['default'] = $default;
                                }
                                break;

                            case 'UNSIGNED':
                                $config['columns'][$column]['type'] = 'unsigned '.$config['columns'][$column]['type'];
                                break;

                            case 'COMMENT':
                                $i += $this->stripQuotedValue($parts, $comment, $i);
                                $config['columns'][$column]['comment'] = $comment;
                                break;

                            case 'ON_UPDATE':
                            case 'CHARACTER_SET':
                            case 'COLLATE':
                                $i++; // ignore value
                                break;

                            default:
                                notice('Unknown part "'.$part.'" in "'.$line.'"');
                                break;
                        }
                    }
                } else { // Key description
                    $exploded = explode(' ', $line);
                    $parts = [];
                    for ($i = 0; $i < count($exploded); ++$i) {
                        $part = $exploded[$i];
                        if (substr($part, 0, 1) === '`') {
                            --$i;
                            $i += $this->stripQuotedValue($exploded, $value, $i, '`');
                            $parts[] = $value;
                        } else {
                            $parts[] = str_replace('`', '', $part); // @todo Parse the "(`id`)" parts. Spaces in columnnames are unlikely but possible.
                        }
                    }
                    switch ($parts[0]) {
                        case 'PRIMARY_KEY':
                            $config['primaryKeys'] = explode(',', substr($parts[1], 1, -1));
                            break;

                        case 'KEY':
                        case 'UNIQUE_KEY':
                        case 'FULLTEXT_KEY':
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
                            $config['columns'][$column]['foreignKeys'][] = [
                                'table' => $foreignTable,
                                'column' => $foreignColumn,
                            ];
                            $schema[$foreignTable]['referencedBy'][] = ['table' => $table, 'column' => $column];
                            break;

                        default:
                            notice('Unknown metadata "'.$parts[0].'" in "'.$line.'"', $lines);
                            break;
                    }
                }
            }
            unset($config);
        }

        return $schema;
    }

    /**
     * Extract the Database schema from a Sqlite database.
     *
     * @param Database $dbLink
     *
     * @return array schema definition
     */
    private function getSchemaSqlite($db, $prefix = false)
    {
        $schema = [];
        $sql = 'SELECT tbl_name FROM sqlite_master WHERE type = "table" AND name != "sqlite_sequence"';
        if ($prefix) {
            $sql .= ' tbl_name LIKE '.$db->quote($prefix.'%');
        }
        $tables = $db->query($sql)->fetchAll();
        // Pass 1: columns
        foreach ($tables as $row) {
            $table = $row['tbl_name'];

            $schema[$table] = [
                'table' => $table,
                'columns' => [],
                'primaryKeys' => [],
            ];
            $config = &$schema[$table];
            if (array_key_exists('referencedBy', $config) == false) {
                $config['referencedBy'] = [];
            }

            $columns = $db->query('PRAGMA table_info('.$table.')');
            foreach ($columns as $column) {
                $name = $column['name'];
                $config['columns'][$name] = [
                    'type' => $column['type'],
                    'null' => ($column['notnull'] == '0'),
                ];
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
                $schema[$table]['columns'][$key['from']]['foreignKeys'][] = [
                    'table' => $key['table'],
                    'column' => $key['to'],
                ];
                $schema[$key['table']]['referencedBy'][] = [
                    'table' => $table,
                    'column' => $key['from'],
                ];
            }
            unset($config);
        }

        return $schema;
    }

    /**
     * Remove the "_id" suffix from a columnname.
     *
     * @param string $column
     *
     * @return string
     */
    private function stripIdSuffix($column)
    {
        if (preg_match('/^(.+)(_id|_code)$/i', $column, $match)) { // A "_code", "_id", "_Id" or "_ID" suffix?
            return $match[1];
        }
        if (preg_match('/^(.*[a-z]+)(Id|ID)$/', $column, $match)) { // Example: CustomerId
            return $match[1];
        }

        return $column;
    }

    /**
     * @param type $parts
     * @param type $value
     * @param type $offset
     * @param type $open
     * @param type $close
     *
     * @return int
     */
    private function stripQuotedValue($parts, &$value, $offset = 0, $open = "'", $close = null)
    {
        $value = '';
        $i = $offset;
        if ($close === null) {
            $close = $open;
        }
        while (array_key_exists($i + 1, $parts)) {
            $value .= $parts[$i + 1];
            if ($i === $offset && substr($value, 0, 1) != $open) { // Not quoted?
                return 1;
            }
            ++$i;
            if (substr($value, -1) == $close) { // Last part of quoted string?
                break; // end for loop
            }
            $value .= ' '; // re-add the exploded space
        }
        $value = substr($value, 1, -1); // strip quotes
        return $i - $offset;
    }

    /**
     * Convert value from the database to a boolean.
     * Doesn't convert all values. (tinyint(1) can also be a number or might be null).
     *
     * @param string $value
     *
     * @return bool
     */
    public static function valueToBool($value)
    {
        if ($value === '1') {
            return true;
        }
        if ($value === '0') {
            return false;
        }

        return $value;
    }

    /**
     * Convert a boolean to a database value.
     * Doesn't convert all values. (A tinyint(1) can also be a number or might be null).
     *
     * @param bool $bool
     *
     * @return string
     */
    public static function boolToValue($bool)
    {
        if (is_bool($bool)) {
            return (string) intval($bool);
        }

        return $bool;
    }

    /**
     * Convert value from the database to an int.
     *
     * @param string $value
     *
     * @return int
     */
    public static function valueToInt($value)
    {
        if (is_string($value) && ($value === '0' || preg_match('/^-?[1-9]{1}[0-9]{0,9}+$/', $value))) {
            return intval($value);
        }

        return $value;
    }
}
