<?php
/**
 * Test de functionaliteit van Record (via de GenericRecord class)
 */
namespace SledgeHammer;
class RecordTest extends DatabaseTestCase {

	/**
	 * var Record $customer  Een customer-record in STATIC mode
	 */
	private $customer;

	function __construct() {
        parent::__construct();
		$this->customer = new SimpleRecord('customers', '__STATIC__', array('dbLink' => $this->dbLink));
	}

	/**
	 * Elke test_* met een schone database beginnen
	 */
	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
//		set_error_handler('SledgeHammer\ErrorHandler_trigger_error_callback');
	}

	function test_create_and_update() {
		$record = $this->createRecord();
		$record->name = 'Naam';
		$record->occupation = 'Beroep';
		$this->assertEqual($record->getChanges(), array(
			'name' => array('next' => 'Naam'),
			'occupation' => array('next' => 'Beroep')
		));
		$this->assertEqual($record->id, null);
		$this->assertEqual($record->getId(), null);
		$record->save();
		$this->assertLastQuery('INSERT INTO customers (name, occupation) VALUES ("Naam", "Beroep")'); // Controleer de query
		$this->assertEqual($record->getChanges(), array());
		$this->assertEqual($record->id, 3);
		$this->assertEqual($record->getId(), 3);
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation'=> 'Software ontwikkelaar'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
			array('id' => '3', 'name' => 'Naam', 'occupation'=> 'Beroep'),
		));
		// Update
		$record->name = 'Andere naam';
		$this->assertEqual($record->getChanges(), array('name' => array(
	    'previous' => 'Naam',
	    'next' => 'Andere naam',
	  )));
		$record->save();
		$this->assertEqual($record->getChanges(), array());
		$this->assertQuery('UPDATE customers SET name = "Andere naam" WHERE id = 3');
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation'=> 'Software ontwikkelaar'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
			array('id' => '3', 'name' => 'Andere naam', 'occupation'=> 'Beroep'),
		));
	}

	function test_find_and_update() {
		$record = $this->getRecord(1);
		$properties = get_object_vars($record);
		$this->assertEqual($properties, array(
			'id' => '1',
			'name' => 'Bob Fanger',
			'occupation' => 'Software ontwikkelaar',
		), 'Object should contain values from the db. %s');
		$this->assertEqual(1, $record->getId());

		$this->assertLastQuery('SELECT * FROM customers WHERE id = 1');
		// Update
		$record->name = 'Ing. Bob Fanger';
		$record->occupation = 'Software developer';
		$record->save();
		$this->assertQuery('UPDATE customers SET name = "Ing. Bob Fanger",occupation = "Software developer" WHERE id = "1"');
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Ing. Bob Fanger', 'occupation'=> 'Software developer'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_update_to_empty_values() {
		$record = $this->getRecord(1);
		$record->occupation = '';
		$record->save();
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation' => ''),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_open_delete_update() {
		$this->getDatabase()->query('DELETE FROM orders WHERE customer_id = 1');
		$record = $this->getRecord(1);
		$record->delete();
		$this->assertLastQuery('DELETE FROM customers WHERE id = "1"');
		$this->expectError('A deleted Record has no properties');
		$record->occupation = 'DELETED?';
		try {
			$record->save();
			$this->fail('Expecting an exception');
		} catch(\Exception $e) {
			$this->assertEqual($e->getMessage(), 'save() not allowed in "DELETED" mode, "UPDATE" or "INSERT" mode required');
		}
		$this->assertTableContents('customers', array(
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_create_and_delete() {
		$record = $this->createRecord();
		try {
			$record->delete();
			$this->fail('Expecting an exception');
		} catch(\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Unexpected mode: "INSERT", expecting "UPDATE"');
		}
	}

	function test_find_with_array() {
		$record1 = $this->customer->find(array('id' => 1));
       	$this->assertQuery('SELECT * FROM customers WHERE id = 1');
		$this->assertEqual($record1->name, 'Bob Fanger');
		$record2 = $this->customer->find(array('id' => '1', 'occupation' => 'Software ontwikkelaar'));
		$this->assertLastQuery('SELECT * FROM customers WHERE id = "1" AND occupation = "Software ontwikkelaar"');
	}
	function test_find_with_sprintf() {
		$record = $this->customer->find('name = ?', 'Bob Fanger');
		$this->assertQuery('SELECT * FROM customers WHERE name = "Bob Fanger"');
		$this->assertEqual($record->name, 'Bob Fanger');
	}

	function test_all() {
		$collection = $this->customer->all();
		$this->assertQueryCount(1); 
		$this->assertQuery('DESCRIBE customers');

		$records = iterator_to_array($collection);
		$this->assertQueryCount(2);	
		$this->assertLastQuery('SELECT * FROM customers');
		$this->assertEqual(count($records), 2);
		$this->assertEqual($records[1]->name, 'Bob Fanger');
		$this->assertEqual($records[2]->name, 'James Bond');
	}

	function test_all_with_array() {
		$collection = $this->customer->all(array('name' => 'James Bond'));
		$this->assertEqual(count($collection), 1);
		$this->assertLastQuery('SELECT * FROM customers WHERE name = "James Bond"');
	}

	function test_all_with_sprintf() {
		$collection = $this->customer->all('name = ?', 'James Bond');
		$this->assertEqual(count($collection), 1);
		$this->assertLastQuery('SELECT * FROM customers WHERE name = "James Bond"');
	}

	function test_belongsTo_detection() {
		restore_error_handler();
		$orders = new SimpleRecord('orders', 1, array('dbLink' => $this->dbLink));
		$this->assertEqual($orders->customer_id, 1); // Sanity check
		$this->assertQueryCount(2); // Sanity check
		$this->assertEqual($orders->customer->name, 'Bob Fanger');  // De customer eigenschap wordt automagisch ingeladen.
		$this->assertQueryCount(4, 'Should generate 1 DESCRIBE and 1 SELECT query');
		$this->assertEqual($orders->customer->occupation, 'Software ontwikkelaar');
		$this->assertQueryCount(4, 'Should not generate more queries'); // Als de customer eenmaal is ingeladen wordt deze gebruikt. en worden er geen query
		$orders->customer_id = 2;
		$this->assertEqual($orders->customer->name, 'James Bond', 'belongsTo should detect a ID change');  // De customer eigenschap wordt automagisch ingeladen.
		$this->assertQueryCount(5, 'Should generate 1 SELECT query');
	}

	function test_belongsTo_setter() {
		$orders = new SimpleRecord('orders', 1, array('dbLink' => $this->dbLink));
		$james = $this->getRecord(2);
		$orders->customer = $james;
		$this->assertEqual($orders->getChanges(), array('customer_id' => array(
			'next' => '2',
			'previous' => '1',
		)));
	}

	function test_belongsTo_recursief_save() {
		$ordersRecord = new SimpleRecord('orders', '__STATIC__', array('dbLink' => $this->dbLink));
		$orders = $ordersRecord->create(array(
			'product' => 'New product',
			'customer' => $this->createRecord(array('name' => 'New customer'))
        ));
       	$orders->save();
		$this->assertEqual($orders->customer_id, 3);
		$this->assertEqual($orders->customer->id, 3);
	}

	/**
	 * @return Record  Een customer-record in INSTANCE/INSERT mode
	 */
	private function createRecord($values = array()) {
		return$this->customer->create($values);
	}

	/**
	 * @return Record  Een customer-record in INSTANCE/UPDATE mode
	 */
	private function getRecord($id) {
		return new SimpleRecord('customers', $id, array('dbLink' => $this->dbLink));
	}
}
?>
