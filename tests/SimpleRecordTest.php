<?php
/**
 * Test de functionaliteit van de SimpleRecord en RepositorySQLBackend
 *
 */
namespace Sledgehammer;
class SimpleRecordTest extends DatabaseTestCase {

	/**
	 * var Record $customer  Een customer-record in STATIC mode
	 */
	private $customer;

	function __construct() {
        parent::__construct();
//		$this->customer = new SimpleRecord('customers', '__STATIC__', array('dbLink' => $this->dbLink));
	}

	/**
	 * Elke test_* met een schone database beginnen
	 */
	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(\PDO::ATTR_DRIVER_NAME).'.sql', $error);
		$repo = new Repository();
		$backend = new DatabaseRepositoryBackend(array($this->dbLink));
		foreach ($backend->configs as $config) {
			$config->class = 'Sledgehammer\SimpleRecord';
		}
		$repo->registerBackend($backend);
		Repository::$instances[__CLASS__] = $repo;
	}

	function test_create_and_update() {
		$record = $this->createCustomer();
		$record->name = 'Naam';
		$record->occupation = 'Beroep';
//		$record->orders = array(); // @todo SimpleRecord should create an array for thes property
		$this->assertEquals($record->getChanges(), array(
			'name' => array('next' => 'Naam'),
			'occupation' => array('next' => 'Beroep')
		));
		$this->assertEquals($record->id, null);
//		$this->assertEquals($record->getId(), null);

		$record->save();
		$this->assertLastQuery("INSERT INTO customers (name, occupation) VALUES ('Naam', 'Beroep')"); // Controleer de query
		$this->assertEquals($record->getChanges(), array());
		$this->assertEquals($record->id, 3);
//		$this->assertEquals($record->getId(), 3);
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation'=> 'Software ontwikkelaar'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
			array('id' => '3', 'name' => 'Naam', 'occupation'=> 'Beroep'),
		));
		// Update
		$record->name = 'Andere naam';
		$this->assertEquals($record->getChanges(), array('name' => array(
			'previous' => 'Naam',
			'next' => 'Andere naam',
		)));
		$record->save();
		$this->assertEquals($record->getChanges(), array());
		$this->assertQuery("UPDATE customers SET name = 'Andere naam' WHERE id = 3");
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation'=> 'Software ontwikkelaar'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
			array('id' => '3', 'name' => 'Andere naam', 'occupation'=> 'Beroep'),
		));
	}

	function test_find_and_update() {
		$record = $this->getCustomer(1);
		// Object should contain values from the db. %s');

		$this->assertInstanceOf('Sledgehammer\HasManyPlaceholder', $record->orders);
		$orders = $record->orders;
		$groups = $record->groups;
		$record->orders = '__PlACEHOLDER__';
		$record->groups = '__PlACEHOLDER__';
		$this->assertEquals(get_object_vars($record), array(
			'id' => '1',
			'name' => 'Bob Fanger',
			'occupation' => 'Software ontwikkelaar',
			'orders' => '__PlACEHOLDER__',
			'groups' => '__PlACEHOLDER__',
		));
		$record->orders = $orders; // restore placeholder
		$record->groups = $groups; // restore placeholder

//		$this->assertEquals(1, $record->getId());

		$this->assertLastQuery('SELECT * FROM customers WHERE id = 1');
		// Update
		$record->name = 'Ing. Bob Fanger';
		$record->occupation = 'Software developer';
		$record->save();
		$this->assertQuery("UPDATE customers SET name = 'Ing. Bob Fanger', occupation = 'Software developer' WHERE id = 1");
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Ing. Bob Fanger', 'occupation'=> 'Software developer'),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_update_to_empty_values() {
		$record = $this->getCustomer(1);
		$record->occupation = '';
		$record->save();
		$this->assertTableContents('customers', array(
			array('id' => '1', 'name' => 'Bob Fanger', 'occupation' => ''),
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_open_delete_update() {
		$this->getDatabase()->query('DELETE FROM orders WHERE customer_id = 1');
		$record = $this->getCustomer(1);
		$record->delete();
		$this->assertLastQuery('DELETE FROM customers WHERE id = 1');
		try {
			$record->occupation = 'DELETED?';
		} catch (\PHPUnit_Framework_Error_Notice $e) {
			$this->assertEquals($e->getMessage(), 'A deleted Record has no properties');
		}
		try {
			$record->save();
			$this->fail('Expecting an exception');
		} catch(\Exception $e) {
			$this->assertEquals($e->getMessage(), 'Sledgehammer\SimpleRecord->save() not allowed on deleted objects');
		}
		$this->assertTableContents('customers', array(
			array('id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'),
		));
	}

	function test_create_and_delete() {
		$record = $this->createCustomer();
		try {
			$record->delete();
			$this->fail('Expecting an exception');
		} catch(\Exception $e) {
			$this->assertEquals($e->getMessage(), 'Removing instance failed, the instance issn\'t stored in the backend');
		}
	}

//	function test_find_with_array() {
//		$record1 = $this->customer->find(array('id' => 1));
//       	$this->assertQuery('SELECT * FROM customers WHERE id = 1');
//		$this->assertEquals($record1->name, 'Bob Fanger');
//		$record2 = $this->customer->find(array('id' => '1', 'occupation' => 'Software ontwikkelaar'));
//		$this->assertLastQuery('SELECT * FROM customers WHERE id = "1" AND occupation = "Software ontwikkelaar"');
//	}
//	function test_find_with_sprintf() {
//		$record = $this->customer->find('name = ?', 'Bob Fanger');
//		$this->assertQuery('SELECT * FROM customers WHERE name = "Bob Fanger"');
//		$this->assertEquals($record->name, 'Bob Fanger');
//	}

	function test_all() {
		$collection = $this->getAllCustomers();
		$this->assertQueryCount(0);
		$records = iterator_to_array($collection);
		$this->assertQueryCount(1);
		$this->assertLastQuery('SELECT * FROM customers');
		$this->assertEquals(count($records), 2);
		$this->assertEquals($records[0]->name, 'Bob Fanger');
		$this->assertEquals($records[1]->name, 'James Bond');
	}

	function test_all_with_array() {
		$collection = $this->getAllCustomers()->where(array('name' => 'James Bond'));
		$this->assertEquals(count($collection), 1);
		$this->assertLastQuery("SELECT COUNT(*) FROM customers WHERE name = 'James Bond'");
	}

//	function test_all_with_sprintf() {
//		$collection = $this->customer->all('name = ?', 'James Bond');
//		$this->assertEquals(count($collection), 1);
//		$this->assertLastQuery('SELECT * FROM customers WHERE name = "James Bond"');
//	}

	function test_belongsTo_detection() {
		$order = $this->getOrder(1);
//		$this->assertEquals($orders->customer_id, 1); // Sanity check
		$this->assertQueryCount(1); // Sanity check
		$this->assertEquals($order->customer->name, 'Bob Fanger');  // De customer eigenschap wordt automagisch ingeladen.
		$this->assertQueryCount(2, 'Should generate 1 SELECT query');
//		$this->assertQueryCount(4, 'Should generate 1 DESCRIBE and 1 SELECT query');
		$this->assertEquals($order->customer->occupation, 'Software ontwikkelaar');
		$this->assertQueryCount(2, 'Should not generate more queries'); // Als de customer eenmaal is ingeladen wordt deze gebruikt. en worden er geen query
//		$order->customer_id = 2;
//		$this->assertEquals($orders->customer->name, 'James Bond', 'belongsTo should detect a ID change');  // De customer eigenschap wordt automagisch ingeladen.
//		$this->assertQueryCount(5, 'Should generate 1 SELECT query');
	}

	function test_belongsTo_setter() {
		$order = $this->getOrder(1);
		$james = $this->getCustomer(2);
		$order->customer = $james;
		$this->assertEquals($order->getChanges(), array('customer_id' => array(
			'next' => '2',
			'previous' => '1',
		)));
	}

	function test_belongsTo_recursief_save() {
		$order = $this->createOrder();
		$order->product = 'New product';

		$order->customer = $this->createCustomer(array('occupation' => 'Consumer'));
		$order->customer->name = 'New customer';
		$order->save();

//		$this->assertEquals($orders->customer_id, 3);
		$this->assertEquals($order->customer->id, 3);
	}

	/**
	 * @return SimpleRecord  Een customer-record in INSERT mode
	 */
	private function createCustomer($values = array()) {
		return SimpleRecord::create('Customer', $values, array('repository' => __CLASS__));
	}

	/**
	 * @return SimpleRecord  Een customer-record in UPDATE mode
	 */
	private function getCustomer($id) {
		return SimpleRecord::find('Customer', $id, array('repository' => __CLASS__));
	}

	/**
	 * @return Collection
	 */
	private function getAllCustomers() {
		return SimpleRecord::all('Customer', array('repository' => __CLASS__));
	}

		/**
	 * @return SimpleRecord  Een order-record in INSERT mode
	 */
	private function createOrder($values = array()) {
		return SimpleRecord::create('Order', $values, array('repository' => __CLASS__));
	}

	/**
	 * @return SimpleRecord  Een order-record in UPDATE mode
	 */
	private function getOrder($id) {
		return SimpleRecord::find('Order', $id, array('repository' => __CLASS__));
	}
}
?>
