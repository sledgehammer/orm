<?php
/**
 * Een Record die zelf (object)eigenschappen aanmaakt aan de hand van de kolommen in de database.
 *
 * @package Record
 */
namespace SledgeHammer;
class SimpleRecord extends Record {
	
	function __construct($table, $id = '__STATIC__', $options = array()) {
		$options = array_merge($options, array('table' => $table));
		if ($id === '__STATIC__') {
			parent::__construct($id, $options);
			return;
		}
		if ($table === '__INSTANCE__' || $table === '__STATIC__') {
			$options = $id;
			$id = $table;
		}
		// @todo $options[??] setting bedenken voor het gebruik inladen van alle kolommen uit de query (ipv de tabel)
		if (array_value($options, 'propertiesByValues') && $id === '__INSTANCE__') {
			if (empty($options['values'])) {
				throw new \Exception('Can\'t create an Instance without $options["values"]');
			}
			$properties =  array_keys($options['values']);
		} else {
			// @todo Waarom niet altijd de properties uit de Tabel gebruiken? is betrouwbaarder dan $options['values']
			$dbLink = isset($options['dbLink']) ? $options['dbLink'] : 'default';
			$info = getDatabase($dbLink)->tableInfo($options['table']);
			$properties = $info['columns'];
		}
		$this->_mode = '__SET_PROPERTY';
		foreach ($properties as $property) {
			$this->$property = null;
		}
		unset($options['propertiesByValues']);
		parent::__construct($id, $options);
	}

	function __set($property, $value) {
		if ($this->_mode == '__SET_PROPERTY') {
			$this->$property = $value; // Eigenschap toevoegen aan het object
			return;
		}
		parent::__set($property, $value);
	}

	/**
	 * Een SimpleRecord heeft altijd alle kolommen die uit de database gehaald worden. (By design)
	 * @return true
	 */
	protected function validateRecord($exclude = array()) {
		return true;
	}
}
?>
