<?php
/**
 * Test de functionaliteit van Record (via de GenericRecord class)
 */
namespace SledgeHammer;
class RecordRelationTest extends DatabaseTestCase {

	/**
	 * Elke test_* met een schone database beginnen
	 */
	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.sql', $error);
//		set_error_handler('SledgeHammer\ErrorHandler_trigger_error_callback');
	}

	function test_hasMany_iterator() {
		$customer = $this->getCustomer(1);
       	$this->assertQueryCount(2, 'Geen queries bij het defineren van een relatie'); // Verwacht een SELECT & DESCRIBE
       	$related = $customer->orders;
		$this->assertQueryCount(2, 'Geen queries voor het opvragen van de relatie');
		//dump($related);
		
		$this->assertEqual($related->count(), 1);
		$this->assertQueryCount(4, 'Zodra de gegevens nodig zijn de DECRIBE & SELECT uitvoeren');
		$this->assertLastQuery('SELECT * FROM orders WHERE customer_id = "1"');
		foreach ($related as $id => $orders) {
			$this->assertEqual($id, 1);
			$this->assertEqual($orders->product, 'Kop koffie');
		}
		$customer->orders[] = $this->createBestelling(array('product' => 'New product', 'id'=> 5));
		$array = iterator_to_array($customer->orders);
		$this->assertEqual(value($array[5]->product), 'New product', 'The iterator should include the "additions"');
	}

	function test_hasMany_array_access() {
		$customer = $this->getCustomer(2);
		$orders = clone $customer->orders[2];
		$this->assertEqual($orders->product, 'Walter PPK 9mm');
		$customer->orders[2]->product = 'Magnum';
		$this->assertEqual($customer->orders[2]->product, 'Magnum', 'Remember changes');
		$customer->orders[2] = $orders;
		$this->assertEqual($orders->product, 'Walter PPK 9mm');
		try {
			$customer->orders[3] = $orders;
			$this->fail('Setting a relation with an ID that doesn\'t match should throw an Exception');
		} catch (\Exception $e) {
			$this->assertEqual($e->getMessage(), 'Key: "3" doesn\'t match the keyColumn: "2"');
       	}
		if ($customer->orders->count() != 2) {
			$this->fail('Sanity check failed');
		}
		$customer->orders[] = $this->createBestelling(array('product' => 'New product')); // Product zonder ID
		$customer->orders[] = $this->createBestelling(array('id' => 7, 'product' => 'Wodka Martini'));
		$this->assertEqual($customer->orders[7]->product, 'Wodka Martini');
		$this->assertEqual($customer->orders->count(), 4, 'There should be 4 items in the relation');
		$customer->save();
		$this->assertQuery('INSERT INTO orders (id, customer_id, product) VALUES (7, "2", "Wodka Martini")');

		$this->assertQuery('INSERT INTO orders (customer_id, product) VALUES ("2", "New product")');
		unset($customer->orders[3]);
		$this->assertEqual($customer->orders->count(), 3, '1 item removed');
		$customer->save();
		$this->assertLastQuery('DELETE FROM orders WHERE id = "3"');
       	//dump($customer->orders);
	}

	function test_hasMany_table_values() {
		$customer = $this->getCustomer(2);
		$products = iterator_to_array($customer->products);
		$this->assertEqual($products, array(
			2 => 'Walter PPK 9mm',
			3 => 'Spycam',
		));
		$this->assertEqual($customer->products[2], 'Walter PPK 9mm');
		$this->assertTrue(isset($customer->products[2]));
		$this->assertFalse(isset($customer->products[5]));
		// @todo Test add & delete
		$customer->products[8] = 'New product';
		//dump($customer->products);
		//
		// Test import
		$customer->products = array(
			2 => 'Walter PPK 9mm',
			17 => 'Wodka Martini',
		);
		$customer->save();
		//dump($customer->products);
    }


	/**
	 * @return Record  Een customer-record in INSTANCE/INSERT mode
	 */
	private function createCustomer($values = array()) {
		$this->getStaticRecord()->create(array());
	}

	/**
	 * @return Record  Een customer-record in INSTANCE/UPDATE mode
	 */
	private function getCustomer($id) {
		return $this->getStaticCustomer()->find($id);
   	}

	/**
	 * @return Record  Een customer-record in STATIC mode
	 */
	private function getStaticCustomer() {
		return new SimpleRecord('customers', '__STATIC__', array(
			'dbLink' => $this->dbLink,
			'hasMany' => array(
				'orders' => new RecordRelation('orders', 'customer_id', array(
					'dbLink' => $this->dbLink,
				)),
				'products' => new RecordRelation('orders', 'customer_id', array(
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
		return new SimpleRecord('orders', '__STATIC__', array(
			'dbLink' => $this->dbLink,
		));
	}
}
?>
