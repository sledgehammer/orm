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
		$repo = new RepositoryTester();
		$this->assertQueryCount(0, 'No queries on contruction');
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		$this->assertQuery('SHOW TABLES');
		$queryCount = self::INSPECT_QUERY_COUNT;
		$this->assertQueryCount($queryCount, 'Sanity check');
		$this->assertTrue($repo->isConfigured('Customer'));
		$this->assertTrue($repo->isConfigured('Order'));
		$this->assertQueryCount($queryCount, 'no additional queries');
	}
	
	function test_getRepository_function() {
		$repo = getRepository(); // get an Empty (master) repository 
		$this->assertFalse($repo->isConfigured('Customer'), 'Sanity check');
		try {
			$repo->getCustomer(1);
			$this->fail('An Exception should be thrown');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Model "Customer" not configured', 'Repository should be empty');
		}
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		$this->assertTrue($repo->isConfigured('Customer'), 'Sanity check');

		$sameRepo = getRepository();
		$this->assertTrue($sameRepo === $repo, 'a second getRepository() call should return the same repository');
	} 
	
	function test_getWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		$customer1 = $repo->getCustomer(1);
		$this->assertEqual($customer1->name, "Bob Fanger");
		$this->assertEqual($customer1->occupation, "Software ontwikkelaar");
		$order1 = $repo->getOrder(1);
		$this->assertEqual($order1->product, 'Kop koffie');
		// id truncation
		try {
			$customer1s = $repo->getCustomer('1s');
			if ($customer1s !== $customer1) {
				$this->fail('id was truncated, but not detected');
			} else {
				$this->fail('id was truncated, but index was corrected');
			}
		} catch (\Exception $e)  {
			$this->assertEqual($e->getMessage(), 'The $id parameter doesn\'t match the retrieved data. {1s} != {1}');
		}
	}
	
	function test_belongsTo() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

		$order2 = $repo->getOrder(2);
		$clone = clone $order2;
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
		
		$this->expectError('This placeholder belongs to an other (cloned?) container');
		$this->assertEqual($clone->customer->name, 'James Bond');
		//	$this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
	}
	
	function test_getWildcardCollection() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));

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
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		
		// Test iterator 
		$c1 = $repo->getCustomer(1);
		$this->assertTrue((gettype($c1->orders) == 'object' && get_class($c1->orders) == 'SledgeHammer\HasManyPlaceholder'), 'The orders property should be an Placeholder');
		foreach ($c1->orders as $order) {
			// do nothing
		}
		$this->assertLastQuery('SELECT * FROM orders WHERE customer_id = "1"');
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
		$clone = clone $c2;
		unset($c2->orders[0]);
		$this->assertEqual(count($c2->orders), 1, 'Unset by array offset');
		$this->assertEqual(gettype($c2->orders), 'array', 'The orders property should be replaced with an array');
		
		$this->expectError('This placeholder belongs to an other (cloned?) container');
		$this->assertEqual($clone->orders[1]->product, 'Spycam');
		//	$this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
	}
	
	function test_getWildcard_preload() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		
		$order = $repo->getOrder(2, true);
		$this->assertIsA($order->customer, 'stdClass', 'Should not be a BelongsToPlaceholder');
		$this->assertIsA($order->customer->orders, 'array', 'Should not be a HasManyPlaceholder');
	}
	
	function test_removeWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		
		$order1 = $repo->getOrder(1);
		// remove by instance
		$repo->removeOrder($order1);
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 2);
		$this->assertLastQuery('DELETE FROM orders WHERE id = "1"');
		// remove by id
		$repo->removeOrder('2');
		$this->assertLastQuery('DELETE FROM orders WHERE id = "2"');

	}

	
	function test_saveWildcard() {
		$repo = new RepositoryTester();
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		
		$c1 = $repo->getCustomer(1);
		$repo->saveCustomer($c1);

		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 1, 'Saving an unmodified instance shouldn\'t generate a query');

		$c1->occupation = 'Webdeveloper';
		$repo->saveCustomer($c1);
		$this->assertLastQuery('UPDATE customers SET occupation = "Webdeveloper" WHERE id = "1"');
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 2, 'Sanity Check');
		$repo->saveCustomer($c1); // Check if the updated data is now bound to the instance
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 2, 'Saving an unmodified instance shouldn\'t generate a query');

		$order2 = $repo->getOrder(2);
		$repo->saveOrder($order2); // Don't autoload belongTo properties 
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 3, 'Saving an unmodified instance shouldn\'t generate a query');
		
		try {
			$order2->customer->id = 1; // Changes the id inside the customer object.
			$repo->saveOrder($order2);
			$this->fail('Dangerous change should throw an Exception');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Change rejected, the index changed from {2} to {1}');
			// @todo check if the message indicated the id-change
		}
		$repo->validate();
		$order2->customer->id = "2"; // restore customer object
		$repo->saveOrder($order2); // The belongTo is autoloaded, but unchanged  
		$this->assertQueryCount(self::INSPECT_QUERY_COUNT + 4, 'Saving an unmodified instance shouldn\'t generate a query');
		
		$c2 = $repo->getCustomer(2);
		$this->assertEqual($c2->orders[0]->product, 'Walter PPK 9mm', 'Sanity check');
		$c2->orders[0]->product = 'Walther PPK';// correct spelling
		$c2->orders[] = $repo->createOrder(array('product' => 'Scuba gear'));
		unset($c2->orders[1]);
		$repo->saveCustomer($c2);
		$this->assertQuery('UPDATE orders SET product = "Walther PPK" WHERE id = "2"');
		$this->assertQuery('INSERT INTO orders (customer_id, product) VALUES ("2", "Scuba gear")');
		$this->assertQuery('DELETE FROM orders WHERE id = "3"');
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
		$repo->registerBackend(new RepositoryDatabaseBackend($this->dbLink));
		return $repo->getCustomer($id);
	}
}
?>
