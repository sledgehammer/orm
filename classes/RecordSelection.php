<?php
/**
 * Verzorgt het itereren door de database records op een OOP manier.
 *
 * @package Record
 */

class RecordSelection extends SQL implements Countable, Iterator {

	/**
	 * @var string $record
	 */
	public $recordClass;
	public $recordOptions;

	public $keyColumn = null;

	private
		$isDirty = true, // Geeft aan of het eisenpakket is aangepast en dat er opnieuw een query gegenereerd moet worden
		$iterator;
	/**
	 * @param Record $record  Een record (in STATIC mode)
	 */
	function __construct($recordOptions = array(), $recordClass = 'SimpleRecord') {
		$this->recordClass = $recordClass;
		$defaultOptions = array(
			'dbLink' => 'default',
			'skipValidation' => true,
		);
		$this->recordOptions = array_merge($defaultOptions, $recordOptions);
	}

    //###################
    // Iterator functies
	//###################

	function current() {
		$options = $this->recordOptions;
		$options['values'] = $this->iterator->current();
		$class = $this->recordClass;
		$instance = new $class('__INSTANCE__', $options);
		$this->recordOptions['skipValidation'] = true; // De record hoeft alleen de eerste keer gecontroleerd te worden.
		return $instance;
	}
	
	function rewind() {
		$this->getValidIterator()->rewind();
	}
	function next() {
		return $this->iterator->next();
	}

	function key() {
		return $this->iterator->key();
	}
	function valid() {
		return $this->iterator->valid();
	}

	/**
	 * implements Countable
	 * $recordQuery->count() of count($recordCollection)
	 * count($mixed) geeft geen fatal error als count() method niet bestaat
	 * return int
	 */
	function count() {
		return count($this->getValidIterator());
	}

	// 
	private function getValidIterator() {
		if ($this->isDirty) {
			$db = getDatabase($this->recordOptions['dbLink']);
			$this->iterator = $db->query($this, $this->keyColumn);
			if ($this->iterator == false) {
				throw new Exception('Invalid results for query "'.$this.'"');
			}
			$this->isDirty = false;
		}
		return $this->iterator;
	}
}
?>
