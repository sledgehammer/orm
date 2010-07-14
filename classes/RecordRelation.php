<?php
/**
 * Verzorgt het itereren door de database records op een OOP manier.
 *
 *
 * @package Record
 */

class RecordRelation extends Object implements ArrayAccess, Countable, Iterator {

	
	private 
		$foreignKey,
		$foreignId,
		$keyColumn = null,
		$valueProperty,

		$table, // @var string $table
		$dbLink = 'default',
		$columns = '*',
		$recordClass = 'SimpleRecord',
		$recordOptions = array();

	private
		$modifications = array(),
		$additions = array(),
		$phantoms = array(), // Dit zijn additions waarvan er pas na het opslaan een key bekend is.
		$deletions = array();
	/**
	 * 2D array met de gegevens van alle records
	 * @var array $result
	 */
	private $previousValues;

	function __construct($table, $foreignKey, $options = array()) {
		$this->table = $table;
		$this->recordOptions['table'] = $table;
		$this->foreignKey = $foreignKey;
		$invalidOptions = array('table', 'foreignKey', 'foreignId', 'modifications', 'additions', 'phantoms', 'deletions');
		$relationOptions = array('keyColumn', 'valueProperty', 'dbLink', 'columns', 'recordClass');
		foreach ($options as $property => $value) {
			if (in_array($property, $invalidOptions)) {
				throw new Exception('Unexpected option: "'.$property.'", use '.implode(', ', $relationOptions).' or recordOptions');
			} elseif (in_array($property, $relationOptions)) {
				$this->$property = $value; //
				if (in_array($property, array('dbLink', 'columns'))) {
					$this->recordOptions[$property] = $value;
				}
			} else {
				$this->recordOptions[$property] = $value;
			}
		}
	}

	/**
	 *
	 * @param int|array $foreignId
	 */
	function init($foreignId) {
		if ($this->foreignId !== null) {
			throw new Exception('Relation is already initialized');
		}
		$this->foreignId = $foreignId;
	}

	/**
	 * Alle relaties bijwerken zodat deze gelijk is aan de meegegeven array.
	 *
	 * @param array $relations
	 */
	function import($data) {
		if (!is_array($data)) {
			throw new Exception('Only arrays are supported');
		}
		if (count($this->deletions) > 0) { // Mist previousValues array een aantal rijen?
			$this->previousValues = null; // forceer het opnieuw inladen van de previousValues.
		}

		// Reset alle wijzigingen
		$this->modifications = array();
		$this->additions = array();
		$this->deletions = array();
		$this->phantoms = array();

		$this->syncPreviousValues();
		$missingRows = $this->previousValues;
		foreach ($data as $key => $value) {
			$this->offsetSet($key, $value);
			if (isset($this->previousValues[$key])) { // Bestaande relatie?
				unset($missingRows[$key]); // Deze relatie niet verwijderen.
			}

		}
		foreach(array_keys($missingRows) as $key) {
			$this->offsetUnset($key); //
		}
	}

  	function save() {
		// Wijzigingen opslaan
		foreach ($this->modifications as $record) {
			$record->save();
		}

		// Nieuwe relaties opslaan
		foreach ($this->additions as $key => $record) {
			$record->save();
			$this->modifications[$key] = $record;
		}
		$this->additions = array();

		foreach ($this->phantoms as $record) {
			$record->save();
			$key = $record->{$this->keyColumn};
			if ($key === null) { // Heeft de additions na het opslaan nog steeds geen $key?
				warning('De (phantom) addition heeft na het opslaan nog steeds geen $key');
			} else {
				$this->modifications[$key] = $record;
			}
		}
		$this->phantoms = array();

		foreach ($this->deletions as $record) {
			$record->delete();
		}
		$this->deletions = array();
		$this->previousValues = null; // previousValues are dirty
	}


	//########################
	// implements ArrayAccess
	//########################
	function offsetExists($key) {
		$this->syncPreviousValues();
		if (isset($this->previousValues[$key]) || isset($this->additions[$key])) {
			return true;
		}
		return false;
	}
	// Waarde opvragen
	function offsetGet($key) {
		if ($this->valueProperty === null) {
			return $this->getRecord($key);
		}
		// @todo Optimaliseren zodat waarde direct uit de previousValues array komt
		$record = $this->getRecord($key);
		return $record->{$this->valueProperty};
   	}

	// Relatie instellen
	function offsetSet($key, $value) {
		if ($this->valueProperty === null) {
			// Het (relatie)Record instellen
			$this->setRecord($value, $key);
			return;
		}
		// Een waarde instellen?
		if ($this->offsetExists($key)) {
			// Een "bestaande" record aanpassen
			$record = $this->getRecord($key);
			$record->{$this->valueProperty} = $value;
			return;
        }
		// Een nieuwe relatie rij toevoegen (en de waarde instellen)
		$class = $this->recordClass;
		$static = new $class('__STATIC__', $this->recordOptions);
		$record = $static->create(array(
			$this->foreignKey => $this->foreignId,		
		));
		if ($key === null) {
			if (($value instanceof Record) == false) { // Is de waarde geen Record
				error('Todo Implement');
			}
		} else {
			$record->{$this->keyColumn} = $key;
		}
		// Controleren of de valueProperty een verwijst naar een hasOne, en zodoende de keyColumn waarde heeft veranderd
		$record->{$this->valueProperty} = $value; 
		$keyValue = $record->{$this->keyColumn};

		if ($keyValue === null) {
			throw new Exception('Unable to determine the keyColumn value');
		}
		if ($key !== null && equals($key, $keyValue) == false) { // Is er een andere key opgegeven dan dat er ingesteld zal worden?
			// Dan is de keyColumn overschreven door de valueProperty (Komt voor bij N-M relaties)
			throw new Exception('Offset "'.$key.'" doesn\'t match the '.$this->keyColumn.': "'.$keyValue.'"'); // The $key and the $keyColumn have diverged
		}
		$this->additions[$keyValue] = $record;
	}

	/**
	 * Een relatie verwijderen.
	 * API: unset($record->items[$key]);
	 * De daarwerkelijke delete() wordt bij de save() uitgevoerd
	 *
	 * @param int|string $key
	 */
	function offsetUnset($key) {
		$record = $this->getRecord($key);
		if ($record !== null) {
			if (isset($this->previousValues[$key])) {
				unset($this->previousValues[$key]);
				unset($this->modifications[$key]);
				$this->deletions[] = $record;
			} elseif (isset($this->additions[$key])) { //
				unset($this->additions[$key]);
			} else {
				throw new Exception('Unable to remove $record, $record isn\'t related');
			}
		}
	}

	//########################
	// implements Countable
	//########################
	function count() {
		$this->syncPreviousValues();
		return count($this->previousValues) + count($this->additions) + count($this->phantoms);
	}

	//########################
	// implements Iterator
	//########################
	
	function rewind() {
		$this->syncPreviousValues();
		reset($this->previousValues);
		reset($this->additions);
	}

	function key() {
		//$this->syncPreviousValues();
		$key = key($this->previousValues);
		if ($key === null) {
			$key = key($this->additions);
		}		
		return $key;
	}

	function current() {
		//$this->syncPreviousValues();
		if ($this->valueProperty === null) {
			return $this->getRecord($this->key(), current($this->previousValues));
		}
		// @todo Optimaliseren zodat waarde direct uit de previousValues array komt
		$record = $this->getRecord($this->key(), current($this->previousValues));
		return $record->{$this->valueProperty};
	}

	function next() {
		//$this->syncPreviousValues();
		if (key($this->previousValues) !== null) {
			next($this->previousValues);
		} else {
			next($this->additions);
		}
	}
	function valid() {
		return ($this->key() !== null);
	}


	/**
	 *
	 * @param int|string $id
	 * @param array|null $values
	 * @return Record
	 */
	private function getRecord($key, $values = null) {
		if (isset($this->modifications[$key])) {
			return $this->modifications[$key];
		}
		if (isset($this->additions[$key])) {
			return $this->additions[$key];
		}
		if ($values === null) {
			$this->syncPreviousValues();
			$values = array_value($this->previousValues, $key);
		}
		if ($values === null) {
			if (isset($this->additions[$key])) {
				return $this->additions[$key];
			}
			notice('No relation defined for key: "'.$key.'"');
			return null;
		}
		$this->modifications[$key] = new $this->recordClass('__INSTANCE__', array_merge($this->recordOptions, array('values' => $values)));
		// @todo Controleer modificaties & gargabe collection
		return $this->modifications[$key];
	}

	/**
	 *
	 * @param Record $record
	 * @param int|string $id (optional)
	 */
	private function setRecord($record, $key = null) {
		if (get_class($record) != $this->recordClass) {
			throw new Exception('Unexpected class "'.get_class($record).'", expection an '.$this->recordClass);
		}
		$keyColumnValue = $record->{$this->keyColumn};
		if ($key !== null) {
			if ($keyColumnValue === null) { // Is er een $id/offset opgegeven, maar heeft de $record (nog) geen keyValue?
				$record->{$this->keyColumn} = $key;
			} elseif (equals($key, $keyColumnValue) == false) {
				throw new Exception('Key: "'.$key.'" doesn\'t match the keyColumn: "'.$keyColumnValue.'"');
			}
       	} else {
			$key = $record->{$this->keyColumn};
		}
		$record->{$this->foreignKey} = $this->foreignId; // @todo De foreignKey is niet altijd beschikbar als public property.
		if ($record->getId() === null) { // Is het een nieuw record?
			if ($key === null) {
				$this->phantoms[] = $record;
           	} else {
				$this->additions[$key] = $record;
			}
		} elseif ($key !== null) {
			if (isset($this->previousValues[$key])) {
				// @todo Controleren of dit een ander record is dan degene in previousValues.
				$this->modifications[$key] = $record;
			} else {
				// Dit record zat nog niet bij deze foreignId.
				$this->additions[$key] = $record;
			}
		} else {
			throw new Exception('Record->'.$this->keyColumn.' has no value');
       	}
	}

	/**
	 * Controleer of de relaties al zijn opgehaald uit de database.
	 * Zo niet, haal dan de gegevens op.
	 * @return void
	 */
	private function syncPreviousValues() {
		if ($this->previousValues !== null) {
			return;
		}
		if ($this->foreignId === null) {
			throw new Exception('Invalid foreignId');
		}
		$db = getDatabase($this->dbLink);
		$where = $db->quoteIdentifier($this->foreignKey).' = '.$db->quote($this->foreignId);
		$sql = new SQL();
       	$sql->select($this->columns)->from($this->table)->where($where);
		if ($this->keyColumn === null) {
			$keys = getDatabase($this->dbLink)->getPrimaryKeys($this->table);
			if (count($keys) == 1) {
				$this->keyColumn = $keys[0];
			} elseif (count($keys) == 2 && in_array($this->foreignKey, $keys)) {
				unset($keys[array_search($this->foreignKey, $keys)]);
				reset($keys);
				$this->keyColumn = current($keys);
			} else {
				throw new Exception('Unable to detect a keyColumn, set the $options[keyColumn] to "'.human_implode('" or "', $keys, '", "').' for example');
           	}
		}
		$result = $db->query($sql, $this->keyColumn);
		if (!$result) {
			throw new Exception('Unable to load relation data');
       	}
		// @todo Controleer op duplicate keys
		$this->previousValues = iterator_to_array($result);
	}
}
?>