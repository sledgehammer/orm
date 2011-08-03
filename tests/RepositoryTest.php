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
	
	function test_getWildcard() {
		$repo = new Repository($this->dbLink);
		dump($repo);
		$customer1 = $repo->getCustomer(1);
		dump($customer1);
		
	}
	
}
?>
