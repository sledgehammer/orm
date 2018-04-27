<?php

/**
 * Test de functionaliteit van de SimpleRecord en RepositorySQLBackend.
 */

namespace SledgehammerTests\Orm;

use Exception;
use PDO;
use PHPUnit\Framework\Error\Notice;
use Sledgehammer\Core\Collection;
use Sledgehammer\Core\Database\Connection;
use Sledgehammer\Orm\Backend\DatabaseRepositoryBackend;
use Sledgehammer\Orm\HasManyPlaceholder;
use Sledgehammer\Orm\Repository;
use Sledgehammer\Orm\SimpleRecord;
use SledgehammerTests\Core\DatabaseTestCase;

class SimpleRecordTest extends DatabaseTestCase
{
    /**
     * var Record $customer  Een customer-record in STATIC mode.
     */
    private $customer;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Elke test_* met een schone database beginnen.
     */
    public function fillDatabase($db)
    {
        $db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(PDO::ATTR_DRIVER_NAME).'.sql', $error);
        $repo = new Repository();
        Repository::$instances[__CLASS__] = $repo;
        $backend = new DatabaseRepositoryBackend([$this->dbLink]);
        foreach ($backend->configs as $config) {
            $config->class = SimpleRecord::class;
        }
        $repo->registerBackend($backend);
    }

    public function testCreateAndUpdate()
    {
        $record = $this->createCustomer();
        $record->name = 'Naam';
        $record->occupation = 'Beroep';
        // $record->orders = array(); // @todo SimpleRecord should create an array for thes property
        $this->assertEquals($record->getChanges(), [
            'name' => ['next' => 'Naam'],
            'occupation' => ['next' => 'Beroep'],
        ]);
        $this->assertEquals($record->id, null);
        // $this->assertEquals($record->getId(), null);

        $record->save();
        $this->assertLastQuery("INSERT INTO customers (name, occupation) VALUES ('Naam', 'Beroep')"); // Controleer de query
        $this->assertEquals($record->getChanges(), []);
        $this->assertEquals($record->id, 3);
        // $this->assertEquals($record->getId(), 3);
        $this->assertTableContents('customers', [
            ['id' => '1', 'name' => 'Bob Fanger', 'occupation' => 'Software ontwikkelaar'],
            ['id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'],
            ['id' => '3', 'name' => 'Naam', 'occupation' => 'Beroep'],
        ]);
        // Update
        $record->name = 'Andere naam';
        $this->assertEquals($record->getChanges(), ['name' => [
                'previous' => 'Naam',
                'next' => 'Andere naam',
        ]]);
        $record->save();
        $this->assertEquals($record->getChanges(), []);
        $this->assertQuery("UPDATE customers SET name = 'Andere naam' WHERE id = 3");
        $this->assertTableContents('customers', [
            ['id' => '1', 'name' => 'Bob Fanger', 'occupation' => 'Software ontwikkelaar'],
            ['id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'],
            ['id' => '3', 'name' => 'Andere naam', 'occupation' => 'Beroep'],
        ]);
    }

    public function testFindAndUpdate()
    {
        $record = $this->getCustomer(1);
        // Object should contain values from the db. %s');

        $this->assertInstanceOf(HasManyPlaceholder::class, $record->orders);
        $orders = $record->orders;
        $groups = $record->groups;
        $ratings = $record->ratings;
        $record->orders = '__PlACEHOLDER__';
        $record->groups = '__PlACEHOLDER__';
        $record->ratings = '__PlACEHOLDER__';
        $this->assertEquals(get_object_vars($record), [
            'id' => '1',
            'name' => 'Bob Fanger',
            'occupation' => 'Software ontwikkelaar',
            'orders' => '__PlACEHOLDER__',
            'groups' => '__PlACEHOLDER__',
            'ratings' => '__PlACEHOLDER__',
        ]);
        $record->orders = $orders; // restore placeholder
        $record->groups = $groups; // restore placeholder
        $record->ratings = $ratings; // restore placeholder
// $this->assertEquals(1, $record->getId());

        $this->assertLastQuery("SELECT * FROM customers WHERE id = '1'");
        // Update
        $record->name = 'Ing. Bob Fanger';
        $record->occupation = 'Software developer';
        $record->save();
        $this->assertQuery("UPDATE customers SET name = 'Ing. Bob Fanger', occupation = 'Software developer' WHERE id = 1");
        $this->assertTableContents('customers', [
            ['id' => '1', 'name' => 'Ing. Bob Fanger', 'occupation' => 'Software developer'],
            ['id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'],
        ]);
    }

    public function testUpdate_to_empty_values()
    {
        $record = $this->getCustomer(1);
        $record->occupation = '';
        $record->save();
        $this->assertTableContents('customers', [
            ['id' => '1', 'name' => 'Bob Fanger', 'occupation' => ''],
            ['id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'],
        ]);
    }

    public function testOpenDeleteUpdate()
    {
        // Remove the refernces
        Connection::instance($this->dbLink)->query('DELETE FROM orders WHERE customer_id = 1');
        Connection::instance($this->dbLink)->query('DELETE FROM memberships WHERE customer_id = 1');
        Connection::instance($this->dbLink)->query('DELETE FROM ratings WHERE customer_id = 1');
        $record = $this->getCustomer(1);
        $record->delete();
        $this->assertLastQuery('DELETE FROM customers WHERE id = 1');
        try {
            $record->occupation = 'DELETED?';
        } catch (Notice $e) {
            $this->assertEquals($e->getMessage(), 'A deleted Record has no properties');
        }
        try {
            $record->save();
            $this->fail('Expecting an exception');
        } catch (Exception $e) {
            $this->assertEquals($e->getMessage(), SimpleRecord::class.'->save() not allowed on deleted objects');
        }
        $this->assertTableContents('customers', [
            ['id' => '2', 'name' => 'James Bond', 'occupation' => 'Spion'],
        ]);
    }

    public function testCreate_and_delete()
    {
        $record = $this->createCustomer();
        try {
            $record->delete();
            $this->fail('Expecting an exception');
        } catch (Exception $e) {
            $this->assertEquals($e->getMessage(), 'Removing instance failed, the instance isn\'t stored in the backend');
        }
    }

    // function testFind_with_array() {
    // $record1 = $this->customer->find(array('id' => 1));
    //        $this->assertQuery('SELECT * FROM customers WHERE id = 1');
    // $this->assertEquals($record1->name, 'Bob Fanger');
    // $record2 = $this->customer->find(array('id' => '1', 'occupation' => 'Software ontwikkelaar'));
    // $this->assertLastQuery('SELECT * FROM customers WHERE id = "1" AND occupation = "Software ontwikkelaar"');
    // }
    // function testFind_with_sprintf() {
    // $record = $this->customer->find('name = ?', 'Bob Fanger');
    // $this->assertQuery('SELECT * FROM customers WHERE name = "Bob Fanger"');
    // $this->assertEquals($record->name, 'Bob Fanger');
    // }

    public function testAll()
    {
        $collection = $this->getAllCustomers();
        $this->assertQueryCount(0);
        $records = iterator_to_array($collection);
        $this->assertQueryCount(1);
        $this->assertLastQuery('SELECT * FROM customers');
        $this->assertEquals(count($records), 2);
        $this->assertEquals($records[0]->name, 'Bob Fanger');
        $this->assertEquals($records[1]->name, 'James Bond');
    }

    public function testAll_with_array()
    {
        $collection = $this->getAllCustomers()->where(['name' => 'James Bond']);
        $this->assertEquals(count($collection), 1);
        $this->assertLastQuery("SELECT COUNT(*) FROM customers WHERE name = 'James Bond'");
    }

    // function testAll_with_sprintf() {
    // $collection = $this->customer->all('name = ?', 'James Bond');
    // $this->assertEquals(count($collection), 1);
    // $this->assertLastQuery('SELECT * FROM customers WHERE name = "James Bond"');
    // }

    public function testBelongsTo_detection()
    {
        $order = $this->getOrder(1);
        // $this->assertEquals($orders->customer_id, 1); // Sanity check
        $this->assertQueryCount(1); // Sanity check
        $this->assertEquals($order->customer->name, 'Bob Fanger');  // De customer eigenschap wordt automagisch ingeladen.
        $this->assertQueryCount(2, 'Should generate 1 SELECT query');
        // $this->assertQueryCount(4, 'Should generate 1 DESCRIBE and 1 SELECT query');
        $this->assertEquals($order->customer->occupation, 'Software ontwikkelaar');
        $this->assertQueryCount(2, 'Should not generate more queries'); // Als de customer eenmaal is ingeladen wordt deze gebruikt. en worden er geen query
// $order->customer_id = 2;
// $this->assertEquals($orders->customer->name, 'James Bond', 'belongsTo should detect a ID change');  // De customer eigenschap wordt automagisch ingeladen.
// $this->assertQueryCount(5, 'Should generate 1 SELECT query');
    }

    public function testBelongsTo_setter()
    {
        $order = $this->getOrder(1);
        $james = $this->getCustomer(2);
        $order->customer = $james;
        $this->assertEquals($order->getChanges(), ['customer_id' => [
                'next' => '2',
                'previous' => '1',
        ]]);
    }

    public function testBelongsTo_recursief_save()
    {
        $order = $this->createOrder();
        $order->product = 'New product';

        $order->customer = $this->createCustomer(['occupation' => 'Consumer']);
        $order->customer->name = 'New customer';
        $order->save();

        $this->assertEquals($order->customer->id, 3);
    }

    /**
     * @return SimpleRecord Een customer-record in INSERT mode
     */
    private function createCustomer($values = [])
    {
        return SimpleRecord::create('Customer', $values, ['repository' => __CLASS__]);
    }

    /**
     * @return SimpleRecord Een customer-record in UPDATE mode
     */
    private function getCustomer($id)
    {
        return SimpleRecord::one('Customer', $id, false, ['repository' => __CLASS__]);
    }

    /**
     * @return Collection
     */
    private function getAllCustomers()
    {
        return SimpleRecord::all('Customer', ['repository' => __CLASS__]);
    }

    /**
     * @return SimpleRecord Een order-record in INSERT mode
     */
    private function createOrder($values = [])
    {
        return SimpleRecord::create('Order', $values, ['repository' => __CLASS__]);
    }

    /**
     * @return SimpleRecord Een order-record in UPDATE mode
     */
    private function getOrder($id)
    {
        return SimpleRecord::one('Order', $id, false, ['repository' => __CLASS__]);
    }
}
