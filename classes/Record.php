<?php
/**
 * Een Record object representateerd een record/rij van een tabel uit een database.
 *
 * - Verzorgt het openen, toevoegen en updaten van rijen(records) in de database op een OOP manier. new Record($id) en $Record->save().
 * - Genereerd veilige queries, (voorkomt SQL injectie, maar kijkt niet naar XSS).
 * - Alle eigenschappen van Record beginnen met een "_" omdat deze niet op veldnamen lijken en dus niet overschreven worden.
 * - 1 object past dan 1 record aan in de database
 *
 * Het is de bedoeling dat in de subclasses de $id het eerste argument is van de __construct.
 * Zodat $c = new Customer(1);
 *
 * Een Record is op de hoogte van relaties dmv '_id' in de kolomnaam. Deze zijn via de $Record->_relations array aan te passen. 
 * Mocht er binnen een object het relatie veld aanwezig zijn wordt deze via een reference& gekoppeld aan de relations array.
 *
 * @todo Veranderingen in de $_foreignKeys array opslaan/weergeven in getChanges().
 * @package Record
 */
namespace SledgeHammer;
abstract class Record extends Object {

	protected
		$_table,
		$_dbLink = 'default',
		$_mode,
		$_columns = '*',
		$_foreignKeys = array();
		
	/**
	 * @var array $_belongsTo
	 *  array('property' => array(
	 *    'record' => Static Record,
	 *    'foreignKey' => string, // Naam van een foreignKey kolom. (Wordt gedetecteerd als de property overeenkomt met de kolomnaam (excl. "_id")
	 *    'instance' => Record instance (wordt gegeneerd/geupdate door __get() & __set())
	 *   )
	 */	
	protected $_belongsTo = array();
	
	/**
	 * @var array $_hasMany  array('items' => RecordRelation);
	 */
	protected $_hasMany = array();

	private $_previousValues;

	/**
	 * @var array $constructOptions  De opties via meegegeven zijn aan het STATIC record. Wordt bij de instances ge-unset()
	 */
	private $constructOptions;

	/**
	 * @param mixed $id  Het id van een Record of "__STATIC__" of "__INSTANCE__"
	 * @param array $options  Mogenlijke opties zijn
	 *   dbLink
	 *   table
	 *   columns
	 *   belongsTo
	 *   hasMany
	 * Overige opties
	 *   excludeColumns  array  Haal deze kolommen niet op uit de database. bv array('password')
	 *   excludeProperties
	 *   ignoreUnknownColumns
	 *   skipValidation
	 *   values
	 */
	function __construct($id = '__STATIC__', $options = array()) {
		// Opties instellen die in alle modi nodig zijn.
		foreach ($options as $property => $value) {
			if (in_array($property, array('dbLink', 'table', 'columns'))) {
				$this->{'_'.$property} = $value;
			}
		}
		if ($this->_table === null) {
			$this->_table = strtolower(get_class($this));
		}
		if (isset($options['excludeColumns'])) {
			// Zijn er kolommen geblacklist, deze velden dan niet opvragen uit de database.
			if ($this->_columns !== '*') {
				throw new \Exception('Use $option[excludeColumns](blacklist) or $option[columns](whitelist + dynamic columns)');
			}
			$info = getDatabase($this->_dbLink)->tableInfo($this->_table);
			$this->_columns = $info['columns'];
			foreach ($options['excludeColumns'] as $column) {
				$index = array_search($column, $this->_columns);
				if ($index === false) {
					throw new \Exception('Column "'.$column.'" not found in the "'.$this->_table.'" table');
				}
				unset($this->_columns[$index]);
			}
		}

		//--------------------------------------
		// Het Record instellen in STATIC mode
		//--------------------------------------
		if ($id === '__STATIC__') {
			$this->_mode = 'STATIC';
			// De properties verwijderen die
			$properties = array_keys(get_object_vars($this));
			foreach ($properties as $property) {
				if (!in_array($property, array('_mode', '_dbLink', '_table', '_columns'))) {
					unset($this->$property);
				}
			}
			//unset($options['excludeColumns']);
			$this->constructOptions = $options;
			return;
		}

		//--------------------------------------
		// Het Record instellen in INSTANCE mode
		//--------------------------------------
		$this->_mode = 'UPDATE';
		unset($this->constructOptions);
		// Controleer of er een onbekende opties zijn meegegeven
		foreach ($options as $option => $value) {
			
			if (!in_array($option, array('dbLink', 'table', 'columns', 'belongsTo', 'hasMany', 'excludeColumns', 'excludeProperties', 'skipValidation', 'values', 'skipUnknownColumns'))) {
				notice('Invalid option: "'.$option.'" with value: "'.$value.'"');
			}
		}
		if ($id === '__INSTANCE__') { // Zijn er gegevens (uit de database) meegegeven?
			if (empty($options['values'])) {
				throw new \Exception('Can\'t create an Instance without $options["values"]');
			}
			$values = $options['values'];
		} else {
			// De gegevens o.b.v. het ID ophalen uit de database halen.
			// @todo Met name arrays extra controleren of het daadwerkijk om het $id gaat.
			$db = getDatabase($this->_dbLink);
			$values = $db->fetch_row($this->buildSelect($id), true);
			if (!$values) {
				throw new \Exception(get_class($this).'('.$db->quote($id).') bestaat niet (meer)');
			}
		}
		// Relaties waarvan de foreignKey in de "values" zitten.
		if (isset($options['belongsTo'])) {
			$this->_belongsTo = $options['belongsTo'];
		}
		// Waardes inladen.
		$this->_previousValues = $values;
		foreach ($values as $column => $value) {
			// Detecteer foreignKeys & relaties
			if (preg_match('/^(.+[a-z]{1})(_id|Id)$/', $column, $match)) {
				if (property_exists($this, $column)) {
					$this->$column = $value;
					$this->_foreignKeys[$column] = &$this->$column;
				} else {
					$this->_foreignKeys[$column] = $value;
				}
				$property = $match[1];
				if (isset($this->_belongsTo[$property]) == false) {
					// Controleer of er een andere property gemapt in op deze foreignKey
					$relationNotDefined = true;
					foreach ($this->_belongsTo as $relation) {
						if (array_value($relation, 'foreignKey') == $column) {
							$relationNotDefined = false;
							break;
						}
					}
					if ($relationNotDefined) {
						$this->_belongsTo[$property] = array(
							'foreignKey' => $column,
						);
					}
				} elseif (isset($this->_belongsTo[$property]['foreignKey']) == false) {
					$this->_belongsTo[$property]['foreignKey'] = $column;
				}
			} else {
				if (value($options['skipUnknownColumns'])) {
					if (property_exists($this, $column) == false) {
						continue;
					}
				} 
				$this->$column = $value; 
			}
		}

		//------------------
		// Relaties
		//------------------
		foreach ($this->_belongsTo as $property => $relation) {
			if (empty($relation['foreignKey'])) {
				warning('Unable to detect foreignKey for belongsTo["'.$property.'"] relation');
			}
		}
		if (isset($options['hasMany'])) {
			$this->_hasMany = $options['hasMany'];
			$idValue = $this->getId();
			if ($idValue !== null) { // Heeft dit record een ID?
				foreach ($this->_hasMany as $property => $relation) {
					if ($relation instanceof RecordRelation) {
						$relation->init($idValue);
					} else {
						throw new \Exception('Unexpected value for $options["hasMany"]["'.$property.'"], expecting an RecordRelation object');
					}
				}
			}
		}

		//------------
		// Validation
		//------------
		if (value($options['skipValidation'])) {
			return;
		}
		$excludeProperties = (isset($options['excludeProperties'])) ? $options['excludeProperties'] : array();
		$properties = array_keys(get_object_vars($this));
		foreach ($properties as $index => $property) {
			if (substr($property, 0, 1) == '_' || in_array($property, $excludeProperties)) {
				unset($properties[$index]);
			}
		}
		$columns = array_keys($this->_previousValues);

		$missingProperties = array_diff($columns, $properties);
		$missingColumns = array_diff($properties, $columns);

		foreach ($missingColumns as $column) {
			notice('Property "'.$column.'" won\'t be saved', 'Add these columns to the "'.$this->_table.'" table.<br>Or add "'.$column.'" to the $options["excludeProperties"] in '.get_class($this).'->__construct()');
		}
		foreach ($missingProperties as $property) {
			if (in_array($property, $excludeProperties)) {
				notice('Excluded property "'.$property.'" is found in the resultset');
			} else {
				notice('Property "'.$property.'" is injected, but not defined in '.get_class($this), 'Add '.$property.' to  class<br>Or add "'.$property.'" to the $options["excludeColumns"]  in '.get_class($this).'->__construct()');
			}
		}
	}


	//#####################
	// *Static* functions
	//#####################

	/**
	 * Een instance opzoeken en retourneren (UPDATE)
	 *
	 * @param mixed $restriction  De $id, een array met array('id' => $id) of een 'id = ?' string gevolgd door $args
	 * @param mixed $args  Voor de sprintf notatie
	 * @throws Exception Als er niet precies 1 record gevonden.
	 * @return Record
	 */
	function find($restriction, $args = null) {
		$this->requireStaticMode();
		$options = $this->constructOptions;
		$arguments = func_get_args();
       	$sql = call_user_func_array(array($this, 'buildSelect'), $arguments); // Roep $this->fetchRow() aan met dezelde parameters as deze functie
		$db = getDatabase($this->_dbLink);
		$options['values'] = $db->fetch_row($sql, true);
		if (!$options['values']) {
			if (is_array($restriction)) {
				throw new \Exception(get_class($this).' niet gevonden'); // Sprintf of ofPrimary key restriction
			} elseif (func_num_args() != 1) {
				throw new \Exception(get_class($this).' niet gevonden'); // Sprintf of ofPrimary key restriction
			} else {// Primary key restriction
				throw new \Exception(get_class($this).': '.$db->quote($restriction).' niet gevonden'); 
			}
			
		}
		$class = get_class($this);
		$instance = new $class('__INSTANCE__', $options);
		$this->constructOptions['skipValidation'] = true;
		return $instance;
	}

	/**
	 * Een nieuwe instance opvragen (INSERT)
	 * De record instance wordt gevult met de database "default" waarden.
	 *
	 * @param array $values  Waardes waarmee de instance word gevuld.
	 * @return Record
	 */
	function create($values = array()) {
		$this->requireStaticMode();
		$options = $this->constructOptions;
        $db = getDatabase($this->_dbLink);
		$info = $db->tableInfo($this->_table);
		$options['values'] = $info['defaults'];
		if (isset($options['columns']) || isset($options['excludeColumns'])) {
			// Niet alle kolommen hebben een bijbehorende property
			$option['skipUnknownColumns'] = true;
		}
		$class = get_class($this);
		$instance = new $class('__INSTANCE__', $options);
		$instance->_mode = 'INSERT';
		$this->constructOptions['skipValidation'] = true;
		set_object_vars($instance, $values);
       	return $instance;
	}

	/**
	 * Vraag een collectie van deze uit dezelfde tabel op
	 *
	 * @param mixed $restriction (optioneel) retricties toevoegen.
	 *	  $record->all();  Genereerd geen extra restricties
	 *    $record->all(array('name' => 'Bob'));  Wordt: WHERE name = "Bob".
	 *    $record->all('name = ? AND address = ?', 'Bob', 'Dorpsstraat 123');  Wordt: WHERE name = "Bob" AND address = "Dorpsstraat 123"
	 *
	 * @return RecordSelection
	 */
	function all($restriction = null, $args = null) {
		$this->requireStaticMode();
		$collection = new RecordSelection(array(
			'dbLink' => $this->_dbLink,
			'table' => $this->_table,
			'skipValidation' => false, // Voor deze RecordSelection zouden de kolommen valide moeten zijn.
		), get_class($this));
		$collection->select($this->_columns);
		$collection->from($this->_table);

		$db = getDatabase($this->_dbLink);
		$keys = $db->getPrimaryKeys($this->_table);
		if (count($keys) == 1) {
			$collection->keyColumn = $keys[0];
		} else {
			// @todo Hash genereren aan de hand van de keys?
		}

		if ($restriction === null) {
			return $collection;
		}
		$arguments = func_get_args();
		$collection->where = call_user_func_array(array($this, 'buildRestriction'), $arguments); // Roep $this->buildRestriction() aan met dezelde parameters as deze functie
		return $collection;
	}
	
	//#####################
	// *Instance* functions
	//#####################
	
	/**
	 * Vraag de (nog niet opgeslagen) wijzigingen op.
	 * @return array array('previous' => 'Oude waarde', 'next' => 'Nieuwe waarde')
	 */
	function getChanges() {
		if (!in_array($this->_mode, array('UPDATE', 'INSERT')) ) {
			throw new \Exception('getChanges() not allowed in "'.$this->_mode.'" mode, "UPDATE" or "INSERT" mode required');
		}
		$changes = array();
		foreach ($this->_previousValues as $property => $value) {
			if (property_exists($this, $property)) {
				if (!equals($this->$property, $value)) { // Is de waarde gewijzigd?			
					$changes[$property] = array(
						'next' => $this->$property,
					);
					if ($this->_mode == 'UPDATE') {
						$changes[$property]['previous'] = $value;
					}
				}
			} else {
				// @todo $_foreignKeys ook bekijken
			}
		} 
		return $changes;
		/*
		$insert_query = $this->_mode == 'INSERT';
		if ($insert_query) {
			if ($this->_relations === NULL) {
				warning('Call '.get_class($this).'->add() or '.get_class($this).'->open($id) before a '.get_class($this).'->save()');
				return false;
			}
			$previous_values = $this->defaults();
			foreach ($previous_values as $property => $value) { // Bij een insert zijn de default waarden de current (tenzij gewijzigd)
				$changes[$property]['next'] = $value; 
			}
		} else {
			$previous_values = $this->_values;
		}
		foreach(get_object_vars($this) as $property => $value) {
			if (!array_key_exists($property, $previous_values)) { // Komt de eigenschap niet voor als kolom?
				continue;
			}
			if (!equals($value, $previous_values[$property])) { // Is de waarde gewijzigd?
				$changes[$property]['next'] = $value;
			}
		}
		foreach ($this->_relations as $column => $value) {
			if (!equals($value, $previous_values[$column])) { // Is de waarde gewijzigd?
				$changes[$column]['next'] = $value;
			}
		}
		if (!$insert_query) { // update query?
			foreach ($changes as $property => $change) {
				$changes[$property] = array(
					'current' => $previous_values[$property],
					'next' => $change['next'],
				);
			}
		}*/
	}
	
	/**
	 * De wijzigingen opslaan in de database 
	 * d.m.v. een INSERT of UPDATE query
	 * 
	 * @return void
	 */
	function save() {
		if (!in_array($this->_mode, array('UPDATE', 'INSERT')) ) {
			throw new \Exception('save() not allowed in "'.$this->_mode.'" mode, "UPDATE" or "INSERT" mode required');
		}
		// Alle gewijzigde belongsTo relaties opslaan
		foreach ($this->_belongsTo as $relation) {
			if (isset($relation['instance'])) {
				$relation['instance']->save();
				if ($this->_foreignKeys[$relation['foreignKey']] === null) { // Was het ID nog niet bekend? (INSERT)
					$this->_foreignKeys[$relation['foreignKey']] = $relation['instance']->getId();
				}
			}
		}
		$db = getDatabase($this->_dbLink);
		$primaryKeys = $db->getPrimaryKeys($this->_table);
		$changes = $this->getChanges();
		if ($this->_mode == 'INSERT') {
			$sqlValues = array();
			foreach ($changes as $column => $change) {
				$sqlValues[$db->quoteIdentifier($column)] = $db->quote($change['next']);
			}
			$result = $db->query('INSERT INTO '.$db->quoteIdentifier($this->_table).' ('.implode(', ', array_keys($sqlValues)).") VALUES (".implode(', ', $sqlValues).')');
		} else { // UPDATE
			if (count($changes) == 0) { // Geen wijzigingen?
				$result = true; // Geen UPDATE query nodig
			} else {
				$pairs = array();
				foreach($changes as $column => $change) {
					$pairs[] = $db->quoteIdentifier($column).' = '.$db->quote($change['next']);
				}
				$where = array();
				foreach ($primaryKeys as $primaryKey) {
					$where[] = $db->quoteIdentifier($primaryKey).' = '.$db->quote($this->_previousValues[$primaryKey]);
				}
				$result = $db->query('UPDATE '.$db->quoteIdentifier($this->_table).' SET '.implode(',', $pairs).' WHERE '.implode(' AND ', $where));
			}
		}
		
		if (!$result) {
			throw new \Exception('Het opslaan van een '.get_class($this).' in "'.$this->_table.'" is mislukt');
		}
		// INSERT met auto_increment?
		if ($this->_mode == 'INSERT' && count($primaryKeys) == 1 && !array_key_exists($primaryKeys[0], $changes)) {
			$primaryKey = $primaryKeys[0];
			$changes[$primaryKey] = array('next' => $db->insert_id);
			if (property_exists($this, $primaryKey)) {
				$this->$primaryKey = $changes[$primaryKey]['next'];
			}
		}
		foreach ($changes as $column => $change) {
			$this->_previousValues[$column] = $change['next'];
		}
		if ($this->_mode == 'INSERT') {
			$this->_mode = 'UPDATE';
			$idValue = $this->getId();
			foreach ($this->_hasMany as $relation) {
				$relation->init($idValue);
			}
		} else {
			// Alle gewijzigde hasMany relaties opslaan
			foreach ($this->_hasMany as $relation) {
				$relation->save();
			}
		}
		$this->_mode = 'UPDATE';
	}
	
	/**
	 * $instance->delete() of $static->delete($id)
	 * 
	 * @param mixed $id  Verplicht bij een static, en verboden bij een instance
	 * @return void
	 */
	function delete($id = null) {
		if ($id !== null) {
			$this->requireStaticMode();
		} else {
			if ($this->_mode === 'STATIC') {
				throw new \Exception('Parameter $id is required');
			}
			if ($this->_mode !== 'UPDATE') {
				throw new \Exception('Unexpected mode: "'.$this->_mode.'", expecting "UPDATE"');
			}
			$id = $this->getId();
		}
		$db = getDatabase($this->_dbLink);
		$primaryKeys = $db->getPrimaryKeys($this->_table);
		$valid = true;
		if (is_array($id)) {
			$valid = count($primaryKeys) == count($id);
			foreach ($primaryKeys as $key) {
				if (isset($id[$key]) == false) {
					$valid = false;
				}
			}
		} elseif (count($primaryKeys) == 1) {
			$id = array(
				$primaryKeys[0] => $id
			);
		} else {
			$valid = false;
		}
		if (!$valid) {
			throw new \Exception('Invalid $id parameter, expecting array("'.implode('" => ?, "', $primaryKeys).'" => ?)');
		}
		$where = array();
		foreach ($primaryKeys as $primaryKey) {
			$where[] = $db->quoteIdentifier($primaryKey).' = '.$db->quote($id[$primaryKey]);
		}
		$sql = 'DELETE FROM '.$db->quoteIdentifier($this->_table).' WHERE '.implode(' AND ', $where);
		if (!$db->query($sql)) {
			throw new \Exception('Verwijderen van '.get_class($this).' "'.implode(' - ', $id).'" is mislukt');
		}
		if ($db->affected_rows != 1) {
			notice('No records verwijderd', array('affected_rows' => $db->affected_rows));
		}
		if ($this->_mode !== 'STATIC') { // Werd de delete() op een instance uitgevoerd? 
			// Remove public properties
			$properties = array_keys(get_object_vars($this));
			foreach ($properties as $property) {
				if (substr($property, 0, 1) != '_') {
					unset($this->$property);
				}
			}
			$this->_mode = 'DELETED';
		}
	}
	
	function __get($property) {
		if ($this->_mode === 'STATIC') {
			notice('A static Record has no "'.$property.'" property');
			return;
		}
		if ($this->_mode === 'DELETED') {
			notice('A deleted Record has no properties');
			return;
		}
		if (array_key_exists($property, $this->_belongsTo)) {
			$relation = $this->_belongsTo[$property];
			$id = $this->_foreignKeys[$relation['foreignKey']];
			if ($id === null) {
				throw new \Exception('Property "'.$property.'" needs a ID value');
			}
			if (empty($relation['record'])) { // record en recordClass onbekend?
				if (isset($relation['recordClass'])) {
					$class = $relation['recordClass'];
				} else {
					// Zoek naar een class met de naam van de property en extend van Record.
					$class = false;
					$possibleClassnames = array(ucfirst($property), 'SledgeHammer\\'.ucfirst($property));
					foreach ($possibleClassnames as $classname) {
						if ($GLOBALS['AutoLoader']->getFilename($class) != null) {
							$GLOBALS['AutoLoader']->define($class);
							$analyzer = new PHPAnalyzer();
							$info = $analyzer->getInfoWithReflection($classname);
							if (value($info['extends']) == 'Record') {
								// @todo 
								$class = $classname;
								break;
							}
						}
					}
				}
				if ($class) {
					$record = new $class('__STATIC__', array('dbLink' => $this->_dbLink));
				} else {
					// Geen geschikte class gevonden, probeer of er een tabel bestaat met dezelde naam als de property
					$record = new SimpleRecord($property, '__STATIC__', array('dbLink' => $this->_dbLink));
				}

				$this->_belongsTo[$property]['record'] = $record;
			} elseif (empty($relation['record'])) { // record is onbekend, maar de recordClass is wel bekend
				$class = $relation['recordClass'];
				$record = new $class('__STATIC__', array('dbLink' => $this->_dbLink));
				$this->_belongsTo[$property]['record'] = $record;
			} else {
				$record = $relation['record'];
			}
			if (empty($relation['instance']) || $relation['instance']->getId() != $id) { // Is er nog een instantie ingeladen of is het ID veranderd?
				$this->_belongsTo[$property]['instance'] = $record->find($id);
			}
			return $this->_belongsTo[$property]['instance'];
		}
		if (array_key_exists($property, $this->_hasMany)) {
			return $this->_hasMany[$property];
		}
		return parent::__get($property);
	}

	function __set($property, $value) {
		if ($this->_mode === 'STATIC') {
			if ($property == 'constructOptions') {
				$this->constructOptions = $value;
			} else {
				notice('A static Record has no "'.$property.'"');
			}
			return;
		}
		if ($this->_mode === 'DELETED') {
			notice('A deleted Record has no properties');
			return;
		}
		if (array_key_exists($property, $this->_belongsTo)) {
			$relation = $this->_belongsTo[$property];
			if (!is_object($value)) {
				notice('Invalid type: "'.gettype($value).'" expecting a '.get_class($relation['record']));
				return;
			}			
			if (($value instanceof Record) == false) {
				notice('Invalid class: "'.get_class($value).'" expecting a Record', get_class($relation['record']));
				return;
			}
			$this->_foreignKeys[$relation['foreignKey']] = $value->getId();
			$this->_belongsTo[$property]['instance'] = $value;
			return;
		}
		if (array_key_exists($property, $this->_hasMany)) {
			$this->_hasMany[$property]->import($value);
			return;
		}
		parent::__set($property, $value);
	}
	
	/**
	 * De waarde van de primaire sleutel (in de database) opvragen.
	 * (Wordt o.a. gebruikt bij het syncen van lazy-loaded eigenschappen)
	 *
	 * @return null|mixed ID 
	 */
	function getId() {
		if ($this->_mode !== 'UPDATE') {
			return NULL; // Dan heeft de id nog geen waarde.
		}
		$db = getDatabase($this->_dbLink);
		$keys = $db->getPrimaryKeys($this->_table);
		if (count($keys) == 1) {
			return $this->_previousValues[$keys[0]]; // @todo Is dit handig?
		} else {
			$values = array();
			foreach ($keys as $key) {
				$values[$key] = $this->_previousValues[$key];
			}
			return $values;
		}
	}

	/**
	 * De SELECT query opbouwen
	 *
	 * @return SQL
	 */
	protected function buildSelect($restriction, $args = null) {
		$sql = new SQL; // Gebruik SQL zodat columns ook een array kan zijn
		$sql->select($this->_columns)->from($this->_table);
		if (func_num_args() == 1 && is_array($restriction) == false) { // PrimaryKey restriction
			$db = getDatabase($this->_dbLink);
			$primaryKeys = $db->getPrimaryKeys($this->_table);
			if (count($primaryKeys) ==  1) {
				$sql->where = $db->quoteIdentifier($primaryKeys[0]).' = '.$db->quote($restriction);
			} else {
				throw new \Exception('Invalid restriction, expecting array("'.implode('" => ?, "', $primaryKeys).'" => ?)'); // $this->_table has a complex primarykey
			}
		} else { // Array of Sprintf restriction
			$arguments = func_get_args();
			$sql->where = call_user_func_array(array($this, 'buildRestriction'), $arguments); // Roep $this->buildRestriction() aan met dezelde parameters as deze functie
		}
		return $sql;
	}

	/**
	 * @throws Exception zodra het object zich niet in static mode bevind.
	 * @return void
	 */
	protected function requireStaticMode() {
		if ($this->_mode === 'STATIC') {
			return;
		}
		$trace = debug_backtrace();
		$function = $trace[1]['function'];
		$params = 	(count($trace[1]['args']) == '0') ? '' : '...';
		throw new \Exception(get_class($this).'->'.$function.'('.$params.') not allowed for instances. $people->'.$function.'(), not $bob->'.$function.'()');
	}

	/**
	 * @throws Exception zodra het object zich niet in instance mode bevind.
	 * @return void
	 */
	protected function requireInstanceMode() {
		if ($this->_mode !== 'STATIC') {
			return;
		}
		$trace = debug_backtrace();
		$function = $trace[1]['function'];
		$params = 	(count($trace[1]['args']) == '0') ? '' : '...';
		throw new \Exception(get_class($this).'->'.$function.'('.$params.') requires an instance. $bob->'.$function.'(), not $people->'.$function.'()');
	}

	/**
	 * Zet een array restriction
	 *  of
	 * Een sprintf notatie 'name = ?', 'Bob'
	 *  om naar een $where string
	 * 
	 * @todo Als de ? binnen een string staat, deze niet meenemen als parameters. Bv: VALUES (?, "what ?") heef maar 1 parameter.
	 *
	 * @param string|array $restriction
	 * @param mixed $args
	 * @param mixed $_
	 * @return string
	 */
	private function buildRestriction($restriction, $args = null) {
		if (func_num_args() == 1 && is_array($restriction) == false) {
			throw new \Exception('2 or more parameters required for a sprintf restriction');
		}
		$db = getDatabase($this->_dbLink);
		if (is_array($restriction)) { // Het is een array restrictie? Bv: array('name' => 'Bob')
			if (count($restriction) == 0) {
				throw new \Exception('Parameter $restriction should be an array with 1 or more elements');
	       	}
			$sqlParts = array();
			foreach ($restriction as $column => $value) {
				$sqlParts[] = $db->quoteIdentifier($column).' = '.$db->quote($value);
			}
			return implode(' AND ', $sqlParts);
		}
		// Sprintf restrictie
		$arguments  = func_get_args();
		array_shift($arguments); // de $sql eraf halen (deze hoeft niet ge-quote() te worden)

		$sql = str_replace("%", "%%", $restriction); // de % escapen voor sprintf.
		$sql = preg_replace(array('/\?/'), array('%s'), $sql, -1, $count); // De "?" omzetten naar "%s"
		if ($count != count($arguments)) {
			throw new \Exception('Number of parameters doesn\'t match number of "?" in statement');
		}

		$arguments  = array_map(array($db, 'quote'), $arguments); // De parameters escapen
		array_unshift($arguments, $sql); // sql terug in de array
		$where = call_user_func_array('sprintf', $arguments);
		return $where;
	}
}
?>
