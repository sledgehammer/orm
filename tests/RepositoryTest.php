<?php
/**
 * RepositoryTest
 */
namespace SledgeHammer;

class RepositoryTest extends DatabaseTestCase {
	
	/**
	 *
	 * @param SledgeHammer\MySQLiDatabase $db 
	 */
	public function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error_message);
	}

	function test_inspectDatabase() {
		$repo = new Repository();
		$this->assertQueryCount(0);
		$repo->inspectDatabase($this->dbLink);
		$this->assertQuery('SHOW TABLES');
		$queryCount = 1 + 2; // 1 show tabel + 1 for each table
		$this->assertQueryCount($queryCount);
		$config = $repo->getConfig('Customer');
		$this->assertQueryCount($queryCount, 'no additional queries');

		$this->assertEqual($config['table'], 'customers', 'table "customers" should be defined as "Customer"model');
	}
	
	function test_getRepository() {
		$repo = getRepository();
		try {
			$repo->getConfig('Customer');
			$this->fail('An Exception should be thrown');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Model "Customer" not configured');
		}
		$repo->inspectDatabase($this->dbLink);
		
		$sameRepo = getRepository();
		$sameRepo->getConfig('Customer'); // Should throw an Exception
	} 
	
	function test_getWildcard() {
		$repo = new Repository($this->dbLink);
		$repo->inspectDatabase($this->dbLink);

		$customer1 = $repo->getCustomer(1);
		$this->assertEqual($customer1->name, "Bob Fanger");

		$order1 = $repo->getOrder(1);
		$this->assertEqual($order1->product, 'Kop koffie');
	}
	
	function test_belongsTo() {
		$repo = new Repository($this->dbLink);
		$repo->inspectDatabase($this->dbLink);
		
		$order1 = $repo->getOrder(1);
		$this->assertLastQuery('SELECT * FROM orders WHERE id = "1"');
		$this->assertEqual($order1->product, 'Kop koffie');
		
		$this->assertEqual($order1->customer->name, "Bob Fanger");
		$this->assertLastQuery('SELECT * FROM customers WHERE id = "1"');
	}
	
}
?>
