<?php

namespace SledgehammerTests\Orm;

use Exception;
use PDO;
use Sledgehammer\Core\Collection;
use Sledgehammer\Core\Database\Connection;
use Sledgehammer\Core\Object;
use Sledgehammer\Orm\Backend\DatabaseRepositoryBackend;
use Sledgehammer\Orm\BelongsToPlaceholder;
use Sledgehammer\Orm\HasManyPlaceholder;
use Sledgehammer\Orm\Repository;
use SledgehammerTests\Core\DatabaseTestCase;
use SledgehammerTests\Orm\Support\RepositoryTester;
use stdClass;

/**
 * RepositoryTest.
 */
class RepositoryTest extends DatabaseTestCase
{
    private $applicationRepositories = [];

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

    public function setUp()
    {
        parent::setUp();
        if (count(Repository::$instances)) {
            $this->applicationRepositories = Repository::$instances;
        }
    }

    /**
     * @param Sledgehammer\Database $db
     */
    public function fillDatabase($db)
    {
        $db->import(dirname(__FILE__).'/rebuild_test_database.'.$db->getAttribute(PDO::ATTR_DRIVER_NAME).'.sql', $error);
    }

    public function tearDown()
    {
        parent::tearDown();
        Repository::$instances = $this->applicationRepositories;
    }

    public function test_inspectDatabase()
    {
        $repo = new RepositoryTester();
        $this->assertQueryCount(0, 'No queries on contruction');
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
//		$this->assertQuery('SHOW TABLES'); // sqlite and mysql use different queries
        $queryCount = $this->queryCountAfterInspectDatabase;
        $this->assertQueryCount($queryCount, 'Sanity check');
        $this->assertTrue($repo->isConfigured('Customer'));
        $this->assertTrue($repo->isConfigured('Order'));
        $this->assertQueryCount($queryCount, 'no additional queries');
    }

    public function test_getWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $customer1 = $repo->getCustomer(1);
        $this->assertEquals('Bob Fanger', $customer1->name);
        $this->assertEquals('Software ontwikkelaar', $customer1->occupation);
        $order1 = $repo->getOrder(1);
        $this->assertEquals('Kop koffie', $order1->product);
    }

    public function test_oneWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $bob = $repo->oneCustomer(array('name' => 'Bob Fanger'));
        $this->assertEquals('1', $bob->id);

        try {
            $bob = $repo->oneCustomer(array('id >=' => '0'));
            $this->fail('A one critery should return only 1 instance or throw an exception');
        } catch (Exception $e) {
            $this->assertEquals('More than 1 "Customer" model matches the conditions', $e->getMessage());
        }
    }

    public function test_customer_not_found_exception()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
        $this->setExpectedException('Exception', 'Record "id = \'-1\'" doesn\'t exist in "customers"');
        @$repo->getCustomer('-1'); // Invalid/not-existing ID
    }

    public function test_detect_id_truncation()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $customer1 = $repo->getCustomer(1);
        if (Connection::instance($this->dbLink)->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->markTestSkipped('SQLite doesn\'t truncate values');
        }
        $this->setExpectedException('Exception', 'The $id parameter doesn\'t match the retrieved data. {1s} != {1}');
        $customer1s = $repo->getCustomer('1s');
    }

    public function test_getRepository_function()
    {
        $repo = Repository::instance(); // get an Empty (master) repository
        $this->assertFalse($repo->isConfigured('Customer'), 'Sanity check');
        try {
            $repo->getCustomer(1);
            $this->fail('An Exception should be thrown');
        } catch (Exception $e) {
            $this->assertEquals($e->getMessage(), 'Unknown model: "Customer"', 'Repository should be empty');
        }
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
        $this->assertTrue($repo->isConfigured('Customer'), 'Sanity check');

        $sameRepo = Repository::instance();
        $this->assertTrue($sameRepo === $repo, 'a second Repository::instance() call should return the same repository');
        // test_AutoGenerated class
        $repo = Repository::instance();
        $customer = $repo->getCustomer(1);
//        $this->setExpectedException('PHPUnit_Framework_Error_Warning', 'Property "superpowers" doesn\'t exist in a Generated\Customer object'); // Show an notice when setting a non-existing property
//        $customer->superpowers = true;
    }

    public function test_belongsTo()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
        $order2 = $repo->getOrder(2);
        try {
            $clone = clone $order2->customer;
        } catch (Exception $e) {
            $this->assertEquals('Cloning is not allowed for repository-bound objects', $e->getMessage());
        }
        $this->assertLastQuery("SELECT * FROM orders WHERE id = '2'");
        $this->assertRelativeQueryCount(1, 'A get*() should execute max 1 query');
        $this->assertEquals($order2->product, 'Walter PPK 9mm');
        $this->assertEquals(get_class($order2->customer), BelongsToPlaceholder::class, 'The customer property should be an placeholder');
        $this->assertEquals($order2->customer->id, '2');
        $this->assertEquals(get_class($order2->customer), BelongsToPlaceholder::class, 'The placeholder should handle the "id" property');
        $this->assertRelativeQueryCount(1, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

        $this->assertEquals($order2->customer->name, 'James Bond', 'Lazy-load the correct data');
        $this->assertLastQuery("SELECT * FROM customers WHERE id = '2'");
        $this->assertFalse($order2->customer instanceof BelongsToPlaceholder, 'The placeholder should be replaced with a real object');
        $this->assertRelativeQueryCount(2, 'Inspecting the id of an belongsTo relation should not generate any queries'); //

        $order3 = $repo->getOrder(3);
        $this->assertFalse($order3->customer instanceof BelongsToPlaceholder, 'A loaded instance should be injected directly into the container object');
        $this->assertEquals($order3->customer->name, 'James Bond', 'Lazy-load the correct data');
        $this->assertLastQuery("SELECT * FROM orders WHERE id = '3'");
        $this->assertRelativeQueryCount(3, 'No customer queries');

        // $this->setExpectedException('PHPUnit_Framework_Error_Notice', 'This placeholder belongs to an other object');
        // $clone->name = 'Clone';
    }

    public function test_allWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $customers = $repo->allCustomers();
        $this->assertRelativeQueryCount(0, 'Delay queries until collections access');
        $customerArray = iterator_to_array($customers);
        $this->assertEquals(count($customerArray), 2, 'Collection should contain all customers');
        $this->assertEquals($customerArray[0]->name, 'Bob Fanger');
        $this->assertEquals($customerArray[1]->name, 'James Bond');
        $this->assertRelativeQueryCount(1, 'Sanity check');

        $counter = 0;
        foreach ($customers as $customer) {
            ++$counter;
        }
        foreach ($customers as $customer) {
            ++$counter;
        }
        $this->assertEquals($counter, (2 * 2), '$collection->rewind() works as expected');
        $this->assertRelativeQueryCount(1, 'Use only 1 query for multiple loops on all customers');
        $this->assertLastQuery('SELECT * FROM customers');

        $names = $repo->allCustomers()->select('name')->toArray();
        $this->assertEquals(array(
            'Bob Fanger',
            'James Bond',
                ), $names);
        $this->assertLastQuery('SELECT name FROM customers');
        $this->assertRelativeQueryCount(2, 'Bypass repository for additional performance');
        $struct = $repo->allCustomers()->select(array('name', 'occupation'), 'id')->toArray();
        $this->assertLastQuery('SELECT id, name, occupation FROM customers');
        $this->assertRelativeQueryCount(3, 'Bypass repository for additional performance');
    }

    public function test_hasManyIteratorInterface()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        // Test iterator
        $c1 = $repo->getCustomer(1);
        $this->assertTrue((gettype($c1->orders) == 'object' && get_class($c1->orders) == HasManyPlaceholder::class), 'The orders property should be an Placeholder');
        foreach ($c1->orders as $order) {
            $this->assertEquals($order->product, 'Kop koffie', 'Only 1 order expected');
        }
        $this->assertLastQuery('SELECT * FROM orders WHERE customer_id = 1');
        $this->assertInstanceOf(Collection::class, $c1->orders, 'The orders property should be replaced with an Collection');
        $this->assertEquals($c1->orders[0]->product, 'Kop koffie', 'Contents should match the order from customer 1');
        $this->assertEquals(count($c1->orders), 1, 'Should only contain the order from customer 1');

        // Test count
        $c2 = $repo->getCustomer(2);
        $this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == HasManyPlaceholder::class), 'The orders property should be an Placeholder');
        $this->assertEquals(count($c2->orders), 2, 'Should only contain the order from customer 2');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');
    }

    public function test_hasManyArrayAccessInterface()
    {
        // Test array access
        $c2 = $this->getDirtyCustomer(2);
        $this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == HasManyPlaceholder::class), 'The orders property should be an Placeholder');
        $this->assertEquals($c2->orders[0]->product, 'Walter PPK 9mm', 'Get by array offset 0');
        $this->assertEquals($c2->orders[1]->product, 'Spycam', 'Get by array offset 1');
        $this->assertEquals(count($c2->orders), 2, 'Should only contain the order from customer 2');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');

        $c2 = $this->getDirtyCustomer(2);
        $this->assertTrue((gettype($c2->orders) == 'object' && get_class($c2->orders) == HasManyPlaceholder::class), 'Sainity check');
        $this->assertTrue(isset($c2->orders[1]), 'array offset exists');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');

        $c2 = $this->getDirtyCustomer(2);
        $this->assertFalse(isset($c2->orders[3]), 'array offset doesn\'t exist');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');

        $c2 = $this->getDirtyCustomer(2);
        $c2->orders[0] = 'test';
        $this->assertEquals($c2->orders[0], 'test', 'Set by array offset');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');

        $c2 = $this->getDirtyCustomer(2);
        try {
            $clone = clone $c2;
        } catch (Exception $e) {
            $this->assertEquals('Cloning is not allowed for repository-bound objects', $e->getMessage());
        }
        unset($c2->orders[0]);
        $this->assertEquals(count($c2->orders), 1, 'Unset by array offset');
        $this->assertInstanceOf(Collection::class, $c2->orders, 'The orders property should be replaced with an Collection');

        // $this->setExpectedException('PHPUnit_Framework_Error_Notice', 'This placeholder is already replaced');
        // $this->assertEquals($clone->orders[1]->product, 'Spycam');
        // $this->fail('clone doesn\'t work with PlaceHolders, but the placeholder should complain');
    }

    public function test_getWildcard_preload()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $order = $repo->getOrder(2, array('preload' => true));
        $this->assertFalse($order->customer instanceof BelongsToPlaceholder, 'Should not be a BelongsToPlaceholder');
        $this->assertInstanceOf(Collection::class, $order->customer->orders, 'Should not be a HasManyPlaceholder');
        $this->assertInstanceOf(Collection::class, $order->customer->groups[0]->customers, 'Should not be a HasManyPlaceholder');
    }

    public function test_removeWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        // remove by id
        $repo->deleteOrder('2');
        $this->assertLastQuery('DELETE FROM orders WHERE id = 2');
        $this->assertRelativeQueryCount(1);

        // remove by instance
        $order1 = $repo->getOrder(1);
        $this->assertCount(1, $order1->customer->orders->toArray());
        $customer = $order1->customer;
        $this->assertRelativeQueryCount(4, 'Sanity check');
        $repo->deleteOrder($order1);
        $this->assertRelativeQueryCount(5);
        $this->assertLastQuery('DELETE FROM orders WHERE id = 1');
        $this->assertCount(0, $customer->orders->toArray());
        $repo->saveCustomer($customer);
        $this->assertRelativeQueryCount(5, 'Saving a connected item should not trigger another DELETE query');
    }

    public function test_saveWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        $c1 = $repo->getCustomer(1);
        $repo->saveCustomer($c1);

        $this->assertRelativeQueryCount(1, 'Saving an unmodified instance shouldn\'t generate a query');
        $c1->occupation = 'Webdeveloper';
        $repo->saveCustomer($c1);
        $this->assertLastQuery("UPDATE customers SET occupation = 'Webdeveloper' WHERE id = 1");
        $this->assertRelativeQueryCount(2, 'Sanity Check');
        $repo->saveCustomer($c1); // Check if the updated data is now bound to the instance
        $this->assertRelativeQueryCount(2, 'Saving an unmodified instance shouldn\'t generate a query');

        $order2 = $repo->getOrder(2);
        $repo->saveOrder($order2); // Don't autoload belongTo properties
        $this->assertRelativeQueryCount(3, 'Saving an unmodified instance shouldn\'t generate a query');
        try {
            $order2->customer->id = 1; // Changes the id inside the customer object.
            $repo->saveOrder($order2);
            $this->fail('Dangerous change should throw an Exception');
        } catch (Exception $e) {
            $this->assertEquals($e->getMessage(), 'Change rejected, the index changed from {2} to {1}');
            // @todo check if the message indicated the id-change
        }
        $repo->validate();
        $order2->customer->id = '2'; // restore customer object
        $repo->saveOrder($order2); // The belongTo is autoloaded, but unchanged
        $this->assertRelativeQueryCount(4, 'Saving an unmodified instance shouldn\'t generate a query');

        $c2 = $repo->getCustomer(2);
        $this->assertEquals($c2->orders[0]->product, 'Walter PPK 9mm', 'Sanity check');
        $c2->orders[0]->product = 'Walther PPK'; // correct spelling
        $c2->orders[] = $repo->createOrder(array('product' => 'Scuba gear'));
        unset($c2->orders[1]);
        $repo->saveCustomer($c2);
        $this->assertQuery("UPDATE orders SET product = 'Walther PPK' WHERE id = 2");
        $this->assertQuery("INSERT INTO orders (customer_id, product) VALUES (2, 'Scuba gear')");
        $this->assertQuery('DELETE FROM orders WHERE id = 3');
        $this->assertEquals($c2->orders[2]->id, '4', 'The id of the instance should be the "lastInsertId()"');
    }

    public function test_reloadWildcard()
    {
        $repo = new RepositoryTester();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        // test reloadModal
        $c1 = $repo->getCustomer(1);
        $c1->name = 'Arnold Schwarzenegger';
        try {
            $repo->reloadCustomer(1);
            $this->fail('When reloading a changed instance, an exception should be thrown');
        } catch (Exception $e) {
            $this->assertTrue(true, 'When reloading a changed instance, an exception should be thrown');
        }
        $repo->reloadCustomer(1, array('discard_changes' => true));
        $this->assertEquals($c1->name, 'Bob Fanger');
        // test reloadPlural
        $c1->name = 'Arnold Schwarzenegger';
        $c2 = $repo->getCustomer(2);
        $c2->name = 'John Connor';
        try {
            $repo->reloadCustomers();
            $this->fail('When reloading a changed instance, an exception should be thrown');
        } catch (Exception $e) {
            $this->assertTrue(true, 'When reloading a changed instance, an exception should be thrown');
        }
        $repo->reloadCustomers(array('discard_changes' => true));
        $this->assertEquals($c1->name, 'Bob Fanger');
        $this->assertEquals($c2->name, 'James Bond');
    }

    public function test_AutoCompleteHelper()
    {
        $repoBase = new Repository();
        $repoBase->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
        $filename = \Sledgehammer\TMP_DIR.'Test_AutoCompleteRepository.php';
        $class = 'AutoCompleteTestRepository';
        $repoBase->writeAutoCompleteHelper($filename, $class);
        include $filename;
        $methods = array_diff(\Sledgehammer\get_public_methods($class), \Sledgehammer\get_public_methods(Repository::class));
        sort($methods);
        $this->assertEquals($methods, array(
            'allCustomers',
            'allGroups',
            'allOrders',
            'createCustomer',
            'createGroup',
            'createOrder',
            'deleteCustomer',
            'deleteGroup',
            'deleteOrder',
            'getCustomer',
            'getGroup',
            'getOrder',
            'oneCustomer',
            'oneGroup',
            'oneOrder',
            'reloadCustomer',
            'reloadCustomers',
            'reloadGroup',
            'reloadGroups',
            'reloadOrder',
            'reloadOrders',
            'saveCustomer',
            'saveGroup',
            'saveOrder',
        ));
        $repo = new \AutoCompleteTestRepository();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink)); // @todo? Write serialized backends into AutoGenerated class?

        $c1 = $repo->getCustomer(1);
        $this->assertEquals($c1->name, 'Bob Fanger');
        $c1->name = 'Charlie Fanger';
        $repo->saveCustomer($c1);
        $this->assertLastQuery("UPDATE customers SET name = 'Charlie Fanger' WHERE id = 1");
        $c1->orders = array();
        $c1->ratings = array();
        $c1->groups = array();
        $repo->saveCustomer($c1);
        $repo->deleteCustomer($c1);
        $this->assertLastQuery('DELETE FROM customers WHERE id = 1');
    }

    public function test_missing_properties()
    {
        $php = 'class CustomerMissingAProperty extends '.Object::class.' {';
        $php .= 'public $id;';
        $php .= 'public $name;';
//		$php .= 'public $occupation;'; the missing property
        $php .= 'public $orders;';
        $php .= 'public $groups;';
        $php .= '}';
        eval($php);

        $backend = new DatabaseRepositoryBackend($this->dbLink);
        $backend->configs['Customer']->class = 'CustomerMissingAProperty';
        $repo = new Repository();
        $repo->registerBackend($backend);
        try {
            $repo->getCustomer(1);
            $this->fail('The missing property should have given a notice.');
        } catch (Exception $e) {
            $this->assertEquals('Property "occupation" doesn\'t exist in a CustomerMissingAProperty object', $e->getMessage(), $e->getMessage());
        }
    }

    public function test_missing_column()
    {
        $php = 'class CustomerWithAnExtraProperty extends '.Object::class.' {';
        $php .= 'public $id;';
        $php .= 'public $name;';
        $php .= 'public $occupation;';
        $php .= 'public $orders;';
        $php .= 'public $groups;';
        $php .= 'public $extra;'; // The extra property / missing column
        $php .= '}';
        eval($php);

        $backend = new DatabaseRepositoryBackend($this->dbLink);
        $backend->configs['Customer']->class = 'CustomerWithAnExtraProperty';
        $repo = new Repository();
        $repo->registerBackend($backend);
        try {
            $repo->getCustomer(1);
            $this->fail('The additional property/missing column should have given a notice.');
        } catch (Exception $e) {
            $this->assertEquals('Unexpected property: "extra" in \CustomerWithAnExtraProperty class for "Customer"', $e->getMessage(), $e->getMessage());
        }
    }

    public function test_export()
    {
        $repo = new Repository();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));
        $c1 = $repo->getCustomer(1);
        $jsonDeep = json_encode($repo->export('Customer', $c1, true));
        $this->assertEquals('{"id":"1","name":"Bob Fanger","occupation":"Software ontwikkelaar","orders":[{"id":"1","product":"Kop koffie"}],"groups":[{"id":"1","title":"Hacker"}],"ratings":[{"id":"1","title":"Hacker","rating":"5"}]}', $jsonDeep);
        $jsonShallow = json_encode($repo->export('Customer', $c1, 0));
        $this->assertEquals('{"id":"1","name":"Bob Fanger","occupation":"Software ontwikkelaar"}', $jsonShallow);
    }

    public function test_create_with_defaults()
    {
        $repo = new Repository();
        $backend = new DatabaseRepositoryBackend($this->dbLink);
        $backend->configs['Order']->defaults['product'] = 'Untitled';
        $backend->configs['Order']->belongsTo['customer']['default'] = 1;
        $repo->registerBackend($backend);
        $order = $repo->create('Order');
        $this->assertRelativeQueryCount(0);
        $this->assertEquals($order->customer->id, 1);
        $this->assertRelativeQueryCount(0, 'Uses a BelongToPlaceholder (no queries)');
        $this->assertEquals($order->customer->name, 'Bob Fanger');
        $this->assertRelativeQueryCount(1, 'but queries the db when needed.');
    }

    public function assertRelativeQueryCount($expectedCount, $message = null)
    {
        return $this->assertQueryCount($this->queryCountAfterInspectDatabase + $expectedCount, $message);
    }

    /**
     * Get a Customer instance where all the properties are still placeholders
     * (Slow/Expensive operation, initializes a new Repository on every call).
     *
     * @param string $id
     *
     * @return stdClass
     */
    private function getDirtyCustomer($id)
    {
        $repo = new Repository();
        $repo->registerBackend(new DatabaseRepositoryBackend($this->dbLink));

        return $repo->getCustomer($id);
    }
}
