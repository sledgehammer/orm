<?php
/**
 * Breidt de UnitTestCase class uit met assert functies voor het controleren van queries en tabellen.
 */
require_once(dirname(__FILE__).'/../../core/tests/DatabaseTestCase.php');

class RecordSelectionTest extends DatabaseTestCase {

	protected $skipRebuildDatabase = true;

	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
		set_error_handler('ErrorHandler_trigger_error_callback');
		$db->query('INSERT INTO klant (name, occupation) VALUES ("Mario", "Loodgieter")');
	}
	
	function test_collection() {
		$db = getDatabase($this->dbLink);
		$info = $db->tableInfo('klant');
		
		$klant = new SimpleRecord('klant', '__STATIC__', array('dbLink' => $this->dbLink));
		$klantenZonderB = $klant->all()->andWhere('name NOT LIKE "B%"');

		$this->assertEqual($klantenZonderB->count(), 2);
		$this->assertLastQuery("SELECT * FROM klant WHERE name NOT LIKE \"B%\"");
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
		$this->assertLastQuery("SELECT * FROM klant");
	}

	private function getKlantCollection() {
		//$klant = new SimpleRecord('klant', '__STATIC__', array('dbLink' => $this->dbLink));
        $collection = new RecordSelection(array(
			'dbLink' => $this->dbLink,
		));
		$collection->select('*')->from('klant');
       	return $collection;
    }


  	function dont_test_sql_composer() {
		
		$sql = new SQL();
		$sql->select('*')
		    ->from('klant AS k')
				->innerJoin('bestelling', 'k.id = klant_id')
				->andWhere('k.id = 1');
		/*$sql->appendTable('klant');
		$sql->appendTable('klant');
*/
		$sql->where[] = 'bestelling.id = 1';
		$this->assertEqual($sql, 
'SELECT
 *
FROM
 klant AS k
 INNER JOIN bestelling ON (k.id = klant_id)
WHERE
 k.id = 1 AND bestelling.id = 1');
		//dump($sql);
		//dump((string) $sql);
		set_error_handler('ErrorHandler_trigger_error_callback');
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