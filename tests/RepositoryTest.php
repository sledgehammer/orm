<?php
/**
 * RepositoryTest
 */
namespace SledgeHammer;

class RepositoryTest extends DatabaseTestCase {
	
	private $applicationRepositories;
	const INSPECT_QUERY_COUNT = 3; // number of queries it n
	
	function setUp() {
		parent::setUp();
		if (isset($GLOBALS['Repositories'])) {
			$this->applicationRepositories = $GLOBALS['Repositories'];
		}
	}
	/**
	 *
	 * @param SledgeHammer\MySQLiDatabase $db 
	 */
	public function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error_message);
	}
	
	public function tearDown() {
		parent::tearDown();
		$GLOBALS['Repositories'] = $this->applicationRepositories;
	}

	function test_inspectDatabase() {
		$repo = new Repository();
		$this->assertQueryCount(0, 'No queries on contruction');
		$repo->inspectDatabase($this->dbLink);
		$this->assertQuery('SHOW TABLES');
		$queryCount = self::INSPECT_QUERY_COUNT;
		$this->assertQueryCount($queryCount, 'Sanity check');
		$config = $repo->getConfig('Customer');
		$this->assertQueryCount($queryCount, 'no additional queries');
		$this->assertEqual($config['table'], 'customers', 'table "customers" should be defined as "Customer"model');
	}
	
	function test_getRepository() {
		$repo = getRepository(); // get an Empty (master) repository 
		try {
			$repo->getConfig('Customer');
			$this->fail('An Exception should be thrown');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Model "Customer" not configured', 'Repository should be empty');
		}
		$repo->inspectDatabase($this->dbLink);
		$config1 = $repo->getConfig('Customer');

		$sameRepo = getRepository();
		$config2 = $sameRepo->getConfig('Customer'); // Should NOT throw an Exception
		$this->assertEqual($config1, $config2, 'a second getRepository should return the same repository');
	} 
	
	function test_getWildcard() {
		$repo = new Repository();
		$repo->inspectDatabase($this->dbLink);

		$customer1 = $repo->getCustomer(1);
		$this->assertEqual($customer1->name, "Bob Fanger");
		$this->assertEqual($customer1->occupation, "Software ontwikkelaar");
		$order1 = $repo->getOrder(1);
		$this->assertEqual($order1->product, 'Kop koffie');
	}
	
	function test_belongsTo() {
		$repo = new Repository();
		$repo->inspectDatabase($this->dbLink);

		$order2 = $repo->getOrder(2);
		$this->assertLastQuery('SELECT * FROM orders WHERE id = 2');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 1, 'A get*() should execute max 1 query');
		$this->assertEqual($order2->product, 'Walter PPK 9mm');
		$this->assertEqual(get_class($order2->customer), 'SledgeHammer\BelongsToPlaceholder', 'The customer property should be an placeholder');
		$this->assertEqual($order2->customer->id, "2");
		$this->assertEqual(get_class($order2->customer), 'SledgeHammer\BelongsToPlaceholder', 'The placeholder should handle the "id" property');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 1, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$this->assertEqual($order2->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery('SELECT * FROM customers WHERE id = "2"');
		$this->assertEqual(get_class($order2->customer), 'stdClass', 'The placeholder should be replaced with a real object');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 2, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

		$order3 = $repo->getOrder(3);
		$this->assertEqual(get_class($order3->customer), 'stdClass', 'A loaded instance should be injected directly into the container object');
		$this->assertEqual($order3->customer->name, "James Bond", 'Lazy-load the correct data');
		$this->assertLastQuery('SELECT * FROM orders WHERE id = 3');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 3, 'No customer queries'); //
	}
	
	function test_getWildcardCollection() {
		$repo = new Repository();
		$repo->inspectDatabase($this->dbLink);

		$customers = $repo->getCustomerCollection();
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT, 'Delay queries until collections access');
//		$this->assertEqual($customerArray, $compare);
		$this->assertEqual(count($customers), 2, 'Collection should contain all customers');
		$customerArray = iterator_to_array($customers);
		$this->assertEqual($customerArray[0]->name, 'Bob Fanger');
		$this->assertEqual($customerArray[1]->name, 'James Bond');

		$counter = 0;
		foreach ($customers as $customer) {
			$counter++;
		}
		foreach ($customers as $customer) {
			$counter++;
		}
		$this->assertEqual($counter, (2 * 2), '$collection->rewind() works as expected');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 1, 'Use only 1 query for multiple loops on all customers');
		$this->assertLastQuery('SELECT * FROM customers');
	}
	
	function test_hasManyIteratorInterface() {
		$repo = new Repository();
		$repo->inspectDatabase($this->dbLink);
		
		// Test iterator 
		$c1 = $repo->getCustomer(1);
		$this->assertTrue((gettype($c1->orders) == 'object' && get_class($c1->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		foreach ($c1->orders as $order) {
			//
		}
		$this->assertLastQuery('SELECT * FROM orders WHERE customer_id = 1');
		$this->assertEqual(gettype($c1->orders), 'array', 'The orders property should be replaced with an array');
		$this->assertEqual($c1->orders[0]->product, 'Kop koffie', 'Contents should match the order from customer 1');		
		$this->assertEqual(count($c1->orders), 1, 'Should only contain the order from customer 1');
		
		// Test count
		$c2 = $repo->getCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		$this->assertEqual(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');
	}
	
	function test_hasManyArrayAccessInterface() {
		restore_error_handler();
		
		// Test array access
		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');

		$this->assertEqual( $c2->orders[0]->product, 'Walter PPK 9mm', 'Get by array offset');
		$this->assertEqual( $c2->orders[1]->product, 'Spycam', 'Get by array offset 1');
		$this->assertEqual(count($c2->orders), 2, 'Should only contain the order from customer 2');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');

		$c2 = $this->getDirtyCustomer(2);
		$this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == 'SledgeHammer\HasManyPlaceholder'), 'Sainity check');
		$this->assertTrue(isset($c2->orders[1]), 'array offset exists');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');

		$c2 = $this->getDirtyCustomer(2);
		$this->assertFalse(isset($c2->orders[3]), 'array offset doesn\'t exist');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');

		$c2 = $this->getDirtyCustomer(2);
		$c2->orders[0] = 'test';
		$this->assertEqual($c2->orders[0], 'test', 'Set by array offset');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');

		
		$c2 = $this->getDirtyCustomer(2);
		unset($c2->orders[0]);
		$this->assertEqual(count($c2->orders), 1, 'Unset by array offset');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');
	}
	
	/**
	 * Get a Customer instance where all the properties are still placeholders
	 * (Slow/Expensive operation, initializes a new Repository on every call)
	 * 
	 * @param string $id
	 * @return stdClass
	 */
	private function getDirtyCustomer($id) {
		$repo = new Repository();
		$repo->inspectDatabase($this->dbLink);
		return $repo->getCustomer($id);
	}
}
?>
