<?php
/**
 * Breidt de UnitTestCase class uit met assert functies voor het controleren van queries en tabellen.
 */
namespace SledgeHammer;
class RecordSelectionTest extends DatabaseTestCase {

	protected $skipRebuildDatabase = true;

	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
//		set_error_handler('SledgeHammer\ErrorHandler_trigger_error_callback');
		$db->query('INSERT INTO customers (name, occupation) VALUES ("Mario", "Loodgieter")');
	}
	
	function test_collection() {
		$db = getDatabase($this->dbLink);
		$info = $db->tableInfo('customers');
		
		$klant = new SimpleRecord('customers', '__STATIC__', array('dbLink' => $this->dbLink));
		$klantenZonderB = $klant->all()->andWhere('name NOT LIKE "B%"');

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
		// @return Record|RecordCollection
		// RecordCollection->find($id) 
	}

	function test_collection_again() {
		restore_error_handler();
		//$db = $this->getDatabase();
		$collection = $this->getKlantCollection();
		// dump($collection);
		$this->assertEqual(3, count($collection));
		$this->assertLastQuery("SELECT * FROM customers");
	}

	private function getKlantCollection() {
		//$klant = new SimpleRecord('klant', '__STATIC__', array('dbLink' => $this->dbLink));
        $collection = new RecordSelection(array(
			'dbLink' => $this->dbLink,
		));
		$collection->select('*')->from('customers');
       	return $collection;
    }


  	function dont_test_sql_composer() {
		
		$sql = new SQL();
		$sql->select('*')
		    ->from('klant AS k')
				->innerJoin('orders', 'k.id = klant_id')
				->andWhere('k.id = 1');
		/*$sql->appendTable('klant');
		$sql->appendTable('klant');
*/
		$sql->where[] = 'orders.id = 1';
		$this->assertEqual($sql, 
'SELECT
 *
FROM
 customers AS c
 INNER JOIN orders ON (c.id = customer_id)
WHERE
 c.id = 1 AND orders.id = 1');
		//dump($sql);
		//dump((string) $sql);
		$db = $this->getDatabase();
		$db->query($sql);
		return;

		$sql = new SQL();
		$sql->select('*')
		    ->from(array('klant'));

		$sql2 = clone $sql;
		$sql->addColumn('column', 'alias');
		$sql2->addColumn('column AS alias');
		dump($sql);
		dump($sql2);
		dump((string) $sql);
				//, 'bestelling'
				//->innerJoin('bestelling', 'k.id = klant_id')
				//->andWhere('k.id = 1');
				//$sql->addColumn('id', 'ID');
		/*$sql->appendTable('klant');
		$sql->appendTable('klant');
		$sql->where[] = array('id = 1');
		dump($sql);
		dump((string) $sql);
*/
	}

}
?>