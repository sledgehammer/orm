<?php
/**
 * Breidt de UnitTestCase class uit met assert functies voor het controleren van queries en tabellen.
 */
namespace Sledgehammer;
class DatabaseCollectionTest extends DatabaseTestCase {

	protected $skipRebuildDatabase = true;


	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(\PDO::ATTR_DRIVER_NAME).'.sql', $error);
		$repo = new Repository();
		$backend = new DatabaseRepositoryBackend(array($this->dbLink));
		foreach ($backend->configs as $config) {
			$config->class = 'Sledgehammer\SimpleRecord';
		}
	}


	function test_collection() {
		$collection = $this->getCustomerCollection();
		$this->assertEquals(2, count($collection));
		$this->assertLastQuery("SELECT COUNT(*) FROM customers");
		$this->assertEquals(2, count($collection->toArray()));
		$this->assertLastQuery("SELECT * FROM customers");
	}

	function test_escaped_where() {
		$collection = $this->getCustomerCollection();
		$emptyCollection = $collection->where(array('name' => "'")); //
		$this->assertEquals(count($emptyCollection->toArray()), 0);
		$this->assertLastQuery("SELECT * FROM customers WHERE name = ''''");
		$this->assertEquals($collection->sql->__toString(), "SELECT * FROM customers", 'Collection->where() does not	modify the orginal collection');
	}

	function test_unescaped_where() {
		restore_error_handler();
		$collection = $this->getCustomerCollection();
		$collection->sql = $collection->sql->andWhere("name LIKE 'B%'"); // Direct modification of the $collection
		$this->assertEquals(count($collection->toArray()), 1);
		$this->assertLastQuery("SELECT * FROM customers WHERE name LIKE 'B%'");
	}

	private function getCustomerCollection() {
		$sql = select('*')->from('customers');
        return new DatabaseCollection($sql, $this->dbLink);
    }
}
?>