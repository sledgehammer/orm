<?php
/**
 * Test de functionaliteit van Record (via de GenericRecord class)
 */
namespace Sledgehammer;
class RecordRelationTest extends DatabaseTestCase {

	/**
	 * Elke test_* met een schone database beginnen
	 * @param Database $db
	 */
	function fillDatabase($db) {
		$db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(\PDO::ATTR_DRIVER_NAME).'.sql', $error);
		$repo = new Repository();
		$backend = new DatabaseRepositoryBackend(array($this->dbLink));
		$repo->registerBackend($backend);
		Repository::$instances[__CLASS__] = $repo;
	}

	function test_hasMany_iterator() {
		$customer = getRepository(__CLASS__)->getCustomer(1);
//		$this->assertQueryCount(2, 'Geen queries bij het defineren van een relatie'); // Verwacht een SELECT & DESCRIBE
//		$this->assertQueryCount(2, 'Geen queries voor het opvragen van de relatie');

		$this->assertEquals(count($customer->orders), 1);

//		$this->assertQueryCount(4, 'Zodra de gegevens nodig zijn de DECRIBE & SELECT uitvoeren');
		$this->assertLastQuery('SELECT * FROM orders WHERE customer_id = 1');
		$related = $customer->orders;

		foreach ($related as $id => $orders) {
//			$this->assertEquals($id, 1); // no longer the default (array != dictionany in json, etc)
			$this->assertEquals($orders->product, 'Kop koffie');
		}
		$customer->orders[] = getRepository(__CLASS__)->createOrder(array('product' => 'New product', 'id'=> 5));
//		$array = iterator_to_array($customer->orders); // no longer an iterator (incompatible with poco)
//		$this->assertEquals(value($array[5]->product), 'New product', 'The iterator should include the "additions"'); // no longer able to set the key based on id (it's  just an array)
	}

	function test_hasMany_array_access() {
		$customer = getRepository(__CLASS__)->getCustomer(2, true);
		$order = clone $customer->orders[0];
		$this->assertEquals($order->product, 'Walter PPK 9mm');
		$customer->orders[0]->product = 'Magnum';
		$this->assertEquals($customer->orders[0]->product, 'Magnum', 'Remember changes');
//		$customer->orders[0] = $order; // after clone a order is no longer connected to the repository
//		$this->assertEquals($order->product, 'Walter PPK 9mm');
		// Relation errors are no longer detected on-access, its just an array
//		try {
//			$customer->orders[2] = $order;
//			$this->fail('Setting a relation with an ID that doesn\'t match should throw an Exception');
//		} catch (\Exception $e) {
//			$this->assertEquals($e->getMessage(), 'Key: "3" doesn\'t match the keyColumn: "2"');
//       	}
//		if (count($customer->orders) != 2) {
//			$this->fail('Sanity check failed');
//		}

		$customer->orders[] = getRepository(__CLASS__)->createOrder(array('product' => 'New product')); // Product zonder ID
		$customer->orders[] = getRepository(__CLASS__)->createOrder(array('id' => 7, 'product' => 'Wodka Martini'));
//		$this->assertEquals($customer->orders[7]->product, 'Wodka Martini'); // No longer has key based on ID, is just an array
		$this->assertEquals(count($customer->orders), 4, 'There should be 4 items in the relation');
		getRepository(__CLASS__)->saveCustomer($customer);
		$this->assertQuery("INSERT INTO orders (customer_id, id, product) VALUES (2, 7, 'Wodka Martini')"); // The "id" comes after the "customer_id" because the belongsTo are mapped before the normal properties
		$this->assertQuery("INSERT INTO orders (customer_id, product) VALUES (2, 'New product')");
		unset($customer->orders[3]);
		$this->assertEquals(count($customer->orders), 3, '1 item removed');
		getRepository(__CLASS__)->saveCustomer($customer);
		$this->assertLastQuery('DELETE FROM orders WHERE id = 7');
	}

	function test_hasMany_table_values() {
		$customer = getRepository(__CLASS__)->getCustomer(2, true);
		$products = $customer->orders->select('product', 'id')->toArray();
		$this->assertEquals($products, array(
			2 => 'Walter PPK 9mm',
			3 => 'Spycam',
		));
		$this->assertEquals($products[2], 'Walter PPK 9mm');
		$this->assertTrue(isset($products[2]));
		$this->assertFalse(isset($products[5]));
		// @todo Test add & delete
//		$customer->products[8] = 'New product';
		//
		// Test import
//		$customer->products = array(
//			2 => 'Walter PPK 9mm',
//			17 => 'Wodka Martini',
//		);
		// @todo Support complex hasMany relations
//		$this->expectError('Saving changes in complex hasMany relations are not (yet) supported.');
//		$this->expectError('Unable to save the change "Wodka Martini" in Customer->products[17]');
//		$this->expectError('Unable to remove item[3]: "Spycam" from Customer->products');
//		getRepository(__CLASS__)->saveCustomer($customer);
    }

	function test_many_to_many_relation() {
		$repo = getRepository(__CLASS__);
		$bob = $repo->getCustomer(1);
		// Reading
		$this->assertCount(1, $bob->groups);
		$this->assertEquals("Hacker", $bob->groups[0]->title);
		$bob->groups[0]->title = 'H4x0r';

		// Changing
		$hackerGroup = getRepository(__CLASS__)->getGroup($bob->groups[0]->id);
		$this->assertEquals('H4x0r', $hackerGroup->title, 'Change should be reflected in the Group instance');

		$this->assertCount(2, $hackerGroup->customers);

		// Saving
		unset($bob->groups[0]);
		$repo->saveCustomer($bob);
		$this->assertLastQuery('DELETE FROM memberships WHERE customer_id = 1 AND group_id = 1');
		$this->assertCount(0, $bob->groups);
		$bob->groups[] = $repo->createGroup(array('title' => 'Movie fanatic'));
		$repo->saveCustomer($bob);
		$this->assertLastQuery('INSERT INTO memberships (customer_id, group_id) VALUES (1, 4)');
		$this->assertQuery("INSERT INTO groups (title) VALUES ('Movie fanatic')");
		$this->assertCount(1, $bob->groups);
		$this->assertCount(1, $hackerGroup->customers, 'The many-to-many relation should be updated on both ends');

		$hackerGroup->customers[] = $bob;
		$repo->saveGroup($hackerGroup);
		$this->assertCount(2, $hackerGroup->customers);
		$this->assertCount(2, $bob->groups, 'The many-to-many relation should be updated on both ends');
	}

//	function test_custom_relation() {
//		$hasMany = array('products' => new RecordRelation('orders', 'customer_id', array(
//			'dbLink' => $this->dbLink,
//			'valueProperty' => 'product',
//		)));
//		$this->fail();
//	}
}
?>
