<?php

/**
 * Test de functionaliteit van Record (via de GenericRecord class).
 */

namespace Sledgehammer\Orm;

use PDO;
use Sledgehammer\Core\Database\Connection;
use Sledgehammer\Orm\Backend\DatabaseRepositoryBackend;
use SledgehammerTests\Core\DatabaseTestCase;

class RecordRelationTest extends DatabaseTestCase
{
    /**
     * @var int Number of queries it takes to inspect the test database (mysql: 6, sqlite: 11)
     */
    private $queryCountAfterInspectDatabase;

    public function __construct()
    {
        parent::__construct();
        DatabaseRepositoryBackend::$cacheTimeout = false; // always inspect database
        if (Connection::instance($this->dbLink)->getAttribute(PDO::ATTR_DRIVER_NAME) == 'mysql') {
            $this->queryCountAfterInspectDatabase = 6;
        } else {
            $this->queryCountAfterInspectDatabase = 11;
        }
    }

    /**
     * Elke test_* met een schone database beginnen.
     *
     * @param Database $db
     */
    public function fillDatabase($db)
    {
        $db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(PDO::ATTR_DRIVER_NAME).'.sql', $error);
        $repo = new Repository();
        $backend = new DatabaseRepositoryBackend(array($this->dbLink));
        $repo->registerBackend($backend);
        Repository::$instances[__CLASS__] = $repo;
    }

    public function test_hasMany_iterator()
    {
        $customer = Repository::instance(__CLASS__)->getCustomer(1);
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
        $customer->orders[] = Repository::instance(__CLASS__)->createOrder(array('product' => 'New product', 'id' => 5));
//		$array = iterator_to_array($customer->orders); // no longer an iterator (incompatible with poco)
//		$this->assertEquals(value($array[5]->product), 'New product', 'The iterator should include the "additions"'); // no longer able to set the key based on id (it's  just an array)
    }

    public function test_hasMany_array_access()
    {
        $customer = Repository::instance(__CLASS__)->getCustomer(2, true);
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

        $customer->orders[] = Repository::instance(__CLASS__)->createOrder(array('product' => 'New product')); // Product zonder ID
        $customer->orders[] = Repository::instance(__CLASS__)->createOrder(array('id' => 7, 'product' => 'Wodka Martini'));
//		$this->assertEquals($customer->orders[7]->product, 'Wodka Martini'); // No longer has key based on ID, is just an array
        $this->assertEquals(count($customer->orders), 4, 'There should be 4 items in the relation');
        Repository::instance(__CLASS__)->saveCustomer($customer);
        $this->assertQuery("INSERT INTO orders (customer_id, id, product) VALUES (2, 7, 'Wodka Martini')"); // The "id" comes after the "customer_id" because the belongsTo are mapped before the normal properties
        $this->assertQuery("INSERT INTO orders (customer_id, product) VALUES (2, 'New product')");
        unset($customer->orders[3]);
        $this->assertEquals(count($customer->orders), 3, '1 item removed');
        Repository::instance(__CLASS__)->saveCustomer($customer);
        $this->assertLastQuery('DELETE FROM orders WHERE id = 7');
    }

    public function test_hasMany_table_values()
    {
        $customer = Repository::instance(__CLASS__)->getCustomer(2, true);
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
//		Repository::instance(__CLASS__)->saveCustomer($customer);
    }

    public function test_many_to_many_relation()
    {
        $repo = Repository::instance(__CLASS__);
        $bob = $repo->getCustomer(1);
        // Reading
        $this->assertCount(1, $bob->groups);
        $this->assertEquals('Hacker', $bob->groups[0]->title);
        $bob->groups[0]->title = 'H4x0r';

        // Changing
        $hackerGroup = Repository::instance(__CLASS__)->getGroup($bob->groups[0]->id);
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
        $this->assertLastQuery('INSERT INTO memberships (group_id, customer_id) VALUES (1, 1)');
        $this->assertCount(2, $bob->groups, 'The many-to-many relation should be updated on both ends');
    }

    public function test_many_to_many_relation_with_fields()
    {
        $repo = Repository::instance(__CLASS__);
        $bob = $repo->getCustomer(1);
        // Reading
        $this->assertCount(1, $bob->ratings);
        $groupRating = $bob->ratings[0];
        $this->assertEquals('Hacker', $groupRating->title); // Access normal property
        $this->assertEquals('5', $groupRating->rating); // Access additional property
        $this->assertInstanceOf(Junction::class, $groupRating);

        // Updating
        $this->assertCount(1, $bob->ratings);
        $groupRating->rating = '4'; // Using a string because an int would change detection when saving the $group
        $repo->saveCustomer($bob);
        $this->assertLastQuery("UPDATE ratings SET rating = '4' WHERE customer_id = 1 AND group_id = 1");

        $group = $repo->getGroup($groupRating->id);
        $this->assertCount(2, $group->ratings->toArray());
        $this->assertLastQuery('SELECT * FROM customers WHERE id IN (1, 2)'); // The many to many for the group was't yet loaded.
        $this->assertEquals('Bob Fanger', $group->ratings[0]->name, 'Sanity check');
        $this->assertEquals(4, $group->ratings[0]->rating);
        $this->assertQueryCount(6, 'Sanity check');
        $repo->saveGroup($group);
        $this->assertQueryCount(6, '0 changes, 0 queries.');
        $group->ratings[0]->rating = 10;
        $repo->saveGroup($group);
        $this->assertQuery('UPDATE ratings SET rating = 10 WHERE customer_id = 1 AND group_id = 1');
        $this->assertQueryCount(7);
        $this->assertEquals(10, $bob->ratings[0]->rating, 'The many-to-many relation should be updated on both ends');

        // Deleting
        unset($group->ratings[0]);
        $repo->saveGroup($group);
        $this->assertLastQuery('DELETE FROM ratings WHERE customer_id = 1 AND group_id = 1');
        $this->assertQueryCount(8);

        $this->assertCount(0, $bob->ratings, 'The many-to-many relation should be updated on both ends');
    }

    public function test_many_to_many_relation_with_mapped_fields()
    {
        $repo = new Repository();
        $backend = new DatabaseRepositoryBackend(array($this->dbLink));
        $repo->registerBackend($backend);
        $backend->configs['Customer']->hasMany['ratings']['fields']['rating'] = 'groupRating';

        $bob = $repo->getCustomer(1);
        // Reading
        $this->assertCount(1, $bob->ratings);
        $groupRating = $bob->ratings[0];
        $this->assertEquals('Hacker', $groupRating->title); // Access normal property
        $this->assertEquals('5', $groupRating->groupRating); // Access additional property
        $this->assertInstanceOf(Junction::class, $groupRating);

        // Updating
        $this->assertCount(1, $bob->ratings);
        $groupRating->groupRating = '4'; // Using a string because an int would change detection when saving the $group
        $repo->saveCustomer($bob);
        $this->assertLastQuery("UPDATE ratings SET rating = '4' WHERE customer_id = 1 AND group_id = 1");

        $group = $repo->getGroup($groupRating->id);
        $this->assertCount(2, $group->ratings->toArray());
        $this->assertLastQuery('SELECT * FROM customers WHERE id IN (1, 2)'); // The many to many for the group was't yet loaded.
        $this->assertEquals('Bob Fanger', $group->ratings[0]->name, 'Sanity check');
        $this->assertEquals(4, $group->ratings[0]->rating);
        $this->assertRelativeQueryCount(6, 'Sanity check');
        $repo->saveGroup($group);
        $this->assertRelativeQueryCount(6, '0 changes, 0 queries.');
        $group->ratings[0]->rating = 10;
        $repo->saveGroup($group);
        $this->assertQuery('UPDATE ratings SET rating = 10 WHERE customer_id = 1 AND group_id = 1');
        $this->assertRelativeQueryCount(7);
        $this->assertEquals(10, $bob->ratings[0]->groupRating, 'The many-to-many relation should be updated on both ends');

        // Deleting
        unset($group->ratings[0]);
        $repo->saveGroup($group);
        $this->assertLastQuery('DELETE FROM ratings WHERE customer_id = 1 AND group_id = 1');
        $this->assertRelativeQueryCount(8);

        $this->assertCount(0, $bob->ratings, 'The many-to-many relation should be updated on both ends');
    }

    public function assertRelativeQueryCount($expectedCount, $message = null)
    {
        return parent::assertQueryCount($this->queryCountAfterInspectDatabase + $expectedCount, $message);
    }

//	function test_custom_relation() {
//		$hasMany = array('products' => new RecordRelation('orders', 'customer_id', array(
//			'dbLink' => $this->dbLink,
//			'valueProperty' => 'product',
//		)));
//		$this->fail();
//	}
}
