<?php
/**
 * Breidt de UnitTestCase class uit met assert functies voor het controleren van queries en tabellen.
 */
namespace SledgeHammer;
class RecordSelectionTest extends DatabaseTestCase {

	protected $skipRebuildDatabase = true;


	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
		$repo = new Repository();
		$backend = new RepositoryDatabaseBackend(array($this->dbLink));
		foreach ($backend->configs as $config) {
			$config->class = 'SledgeHammer\SimpleRecord';
		}
		$repo->registerBackend($backend);
		$GLOBALS['Repositories'][__CLASS__] = $repo;
		$db->query('INSERT INTO customers (name, occupation) VALUES ("Mario", "Loodgieter")');
//		set_error_handler('SledgeHammer\ErrorHandler_trigger_error_callback');
	}

	function dont_test_collection() {
		$collection = $this->getKlantCollection();
		$klantenZonderB->sql = $collection->sql->andWhere('name NOT LIKE "B%"');

		$this->assertEqual($klantenZonderB->count(), 2);
		$this->assertLastQuery("SELECT * FROM customers WHERE name NOT LIKE \"B%\"");
/*
		$klanten  = $klant->all();
		$rCollection = new RecordSelection($klant);
		$notEqual = serialize($klanten) != serialize($rCollection);
		$rCollection->skipRecordValidation  = false;
		$equal =  serialize($klanten) == serialize($rCollection);
		$this->assertTrue($notEqual && $equal, 'The only difference between a Custom collection and a Record::all() collection should be the validation setting');
		*/
	}

	function test_collection_again() {
		//$db = $this->getDatabase();
		$collection = $this->getKlantCollection();
		// dump($collection);
		$this->assertEqual(3, count($collection));
		$this->assertLastQuery("SELECT * FROM customers");
	}

	private function getKlantCollection() {
		$sql = select('*')->from('customers');
        $collection = new DatabaseCollection($sql, $this->dbLink);
       	return $collection;
    }


  	function test_sql_composer() {
		$sql = select('*')
		    ->from('customers AS c')
			->innerJoin('orders', 'c.id = customer_id')
			->andWhere('c.id = 1');
		$sql->where[] = 'orders.id = 1';
		$this->assertEqual((string)$sql, 'SELECT * FROM customers AS c INNER JOIN orders ON (c.id = customer_id) WHERE c.id = 1 AND orders.id = 1');
	}
}
?>