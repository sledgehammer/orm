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
		$repo = new Repository($this->dbLink);
		$repo->inspectDatabase($this->dbLink);

		$customer1 = $repo->getCustomer(1);
		$this->assertEqual($customer1->name, "Bob Fanger");
		$this->assertEqual($customer1->occupation, "Software ontwikkelaar");
		$order1 = $repo->getOrder(1);
		$this->assertEqual($order1->product, 'Kop koffie');
	}
	
	function test_belongsTo() {
		$repo = new Repository($this->dbLink);
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
		$repo = new Repository($this->dbLink);
		$repo->inspectDatabase($this->dbLink);
		restore_error_handler();

		$customers = $repo->getCustomerCollection();
//		$customerArray = iterator_to_array($customers);
//		$this->assertEqual($customerArray, $compare);
		foreach ($customers as $customer) {
//			dump($customer);
		}
//		dump($customers);
//		dump($repo);
	}
	
	function test_hasMany() {
		$repo = new Repository($this->dbLink);
		$repo->inspectDatabase($this->dbLink);
		
		$c1 = $repo->getCustomer(1);
		$orders = iterator_to_array($c1->orders);
		dump($orders);
	}
}
?>
