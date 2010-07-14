<?php

// DEPRECATED Logging is optioneel

/**
 * De log database connectie is niet nodig als je geen wijzigingen in een record aanbrengt
 * /

require_once(dirname(__FILE__).'/init.php');

class LogRequiredTest extends DatabaseTestCase {

	private 
		$db_name = 'DAO_RECORD_TEST';

	/**
	 * Elke test_* met een schone database beginnen
	 * /
	function setUp() {
		restore_error_handler();
		$this->database_connect_once('development', dirname(__FILE__).'/database.ini');
		$GLOBALS['User'] = new stdClass;
		$GLOBALS['User']->id = 1;
		$GLOBALS['User']->fullname = 'SystemTest';

		$Database = getDatabase($this->database_link);
		$Database->query('CREATE DATABASE IF NOT EXISTS '.$this->db_name);
		$Database->select_db($this->db_name);
		$Database->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
		parent::setUp();
	}

	/**
	 * Na elke test de database verwijderen
	 * /
	function tearDown() {
		parent::tearDown();
		$Database = getDatabase($this->database_link);
		$Database->query('DROP DATABASE '.$this->db_name);
	}

	function t est_read_only_record() {
		LogChangesHook::$log_database_link = 'logdb_not_required';
		$Record = new GenericRecord('normaltable', 'id', $this->database_link);
		$this->assertTrue($Record->open(1), 'open() should work ');
		$this->assertEqual($Record->name, 'Bob');
		$this->assertTrue($Record->save()); // Als er geen wijzigingen zijn gaat het nog goed... (maar dat is toeval / niet by design) 
	}

	function t est_read_and_write_record() {
		LogChangesHook::$log_database_link = 'logdb_is_required';
		$Record = new GenericRecord('normaltable', 'id', $this->database_link);
		$Record->open(1);
		$Record->name = 'Changed';
		try {
			$test = $Record->save();
			$this->fail('save() should throw an Exception');
		} catch(Exception $Exception) {
			$this->assertEqual($Exception->getMessage(), 'Database connection: $GLOBALS[\'Databases\'][\'logdb_is_required\'] is not configured');
		}
	}
}
*/
?>
