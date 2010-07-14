<?php
/**
 * Test de functionaliteit van Record (via de GenericRecord class)
 */

require_once(dirname(__FILE__).'/../../core/tests/DatabaseTestCase.php');

class RecordRelationTest extends DatabaseTestCase {

	/**
	 * Elke test_* met een schone database beginnen
	 */
	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
		set_error_handler('ErrorHandler_trigger_error_callback');
	}

	function test_hasMany_iterator() {
		$klant = $this->getKlant(1);
       	$this->assertQueryCount(2, 'Geen queries bij het defineren van een relatie'); // Verwacht een SELECT & DESCRIBE
       	$related = $klant->bestellingen;
		$this->assertQueryCount(2, 'Geen queries voor het opvragen van de relatie');
		//dump($related);
		
		$this->assertEqual($related->count(), 1);
		$this->assertQueryCount(4, 'Zodra de gegevens nodig zijn de DECRIBE & SELECT uitvoeren');
		$this->assertLastQuery('SELECT * FROM bestelling WHERE klant_id = "1"');
		foreach ($related as $id => $bestelling) {
			$this->assertEqual($id, 1);
			$this->assertEqual($bestelling->product, 'Kop koffie');
		}
		$klant->bestellingen[] = $this->createBestelling(array('product' => 'New product', 'id'=> 5));
		$array = iterator_to_array($klant->bestellingen);
		$this->assertEqual(value($array[5]->product), 'New product', 'The iterator should include the "additions"');
	}

	function test_hasMany_array_access() {
		$klant = $this->getKlant(2);
		$bestelling = clone $klant->bestellingen[2];
		$this->assertEqual($bestelling->product, 'Walter PPK 9mm');
		$klant->bestellingen[2]->product = 'Magnum';
		$this->assertEqual($klant->bestellingen[2]->product, 'Magnum', 'Remember changes');
		$klant->bestellingen[2] = $bestelling;
		$this->assertEqual($bestelling->product, 'Walter PPK 9mm');
		try {
			$klant->bestellingen[3] = $bestelling;
			$this->fail('Setting a relation with an ID that doesn\'t match should throw an Exception');
		} catch (Exception $e) {
			$this->assertEqual($e->getMessage(), 'Key: "3" doesn\'t match the keyColumn: "2"');
       	}
		if ($klant->bestellingen->count() != 2) {
			$this->fail('Sanity check failed');
		}
		$klant->bestellingen[] = $this->createBestelling(array('product' => 'New product')); // Product zonder ID
		$klant->bestellingen[] = $this->createBestelling(array('id' => 7, 'product' => 'Wodka Martini'));
		$this->assertEqual($klant->bestellingen[7]->product, 'Wodka Martini');
		$this->assertEqual($klant->bestellingen->count(), 4, 'There should be 4 items in the relation');
		$klant->save();
		$this->assertQuery('INSERT INTO bestelling (id, klant_id, product) VALUES (7, "2", "Wodka Martini")');

		$this->assertQuery('INSERT INTO bestelling (klant_id, product) VALUES ("2", "New product")');
		unset($klant->bestellingen[3]);
		$this->assertEqual($klant->bestellingen->count(), 3, '1 item removed');
		$klant->save();
		$this->assertLastQuery('DELETE FROM bestelling WHERE id = "3"');
       	//dump($klant->bestellingen);
	}

	function test_hasMany_table_values() {
		$klant = $this->getKlant(2);
		$products = iterator_to_array($klant->products);
		$this->assertEqual($products, array(
			2 => 'Walter PPK 9mm',
			3 => 'Spycam',
		));
		$this->assertEqual($klant->products[2], 'Walter PPK 9mm');
		$this->assertTrue(isset($klant->products[2]));
		$this->assertFalse(isset($klant->products[5]));
		// @todo Test add & delete
		$klant->products[8] = 'New product';
		//dump($klant->products);
		//
		// Test import
		$klant->products = array(
			2 => 'Walter PPK 9mm',
			17 => 'Wodka Martini',
		);
		$klant->save();
		//dump($klant->products);
    }


	/**
	 * @return Record  Een klant-record in INSTANCE/INSERT mode
	 */
	private function createKlant($values = array()) {
		$this->getStaticRecord()->create(array());
	}

	/**
	 * @return Record  Een klant-record in INSTANCE/UPDATE mode
	 */
	private function getKlant($id) {
		return $this->getStaticKlant()->find($id);
   	}

	/**
	 * @return Record  Een klant-record in STATIC mode
	 */
	private function getStaticKlant() {
		return new SimpleRecord('klant', '__STATIC__', array(
			'dbLink' => $this->dbLink,
			'hasMany' => array(
				'bestellingen' => new RecordRelation('bestelling', 'klant_id', array(
					'dbLink' => $this->dbLink,
				)),
				'products' => new RecordRelation('bestelling', 'klant_id', array(
					'dbLink' => $this->dbLink,
					'valueProperty' => 'product',
				)),
			)
		));
	}
	
	/**
	 * @return Record
	 */
	private function createBestelling($values = array()) {
		return $this->getStaticBestelling()->create($values);
	}

	private function getStaticBestelling() {
		return new SimpleRecord('bestelling', '__STATIC__', array(
			'dbLink' => $this->dbLink,
		));
	}
}
?>
