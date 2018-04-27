<?php

namespace SledgehammerTests\Orm;

use PDO;
use Sledgehammer\Core\Collection;
use Sledgehammer\Orm\Backend\DatabaseRepositoryBackend;
use Sledgehammer\Orm\Repository;
use SledgehammerTests\Core\DatabaseTestCase;

/**
 * Uses the sample database.
 * A "Customer" with many "orders", which can be a "member" of one or more "groups".
 * an a contrived example where the "group" "rates" the "customers"
 */
class DatabaseRepositoryBackendTest extends DatabaseTestCase
{
    protected $skipRebuildDatabase = true;

    /**
     * @var DatabaseRepositoryBackend
     */
    private $backend;

    public function testConfigs()
    {
        $configs = new Collection($this->backend->configs);
        $actual = $configs->orderBy('name')->select('name', null)->toArray();
        $expected = ['Customer', 'Group', 'Order'];
        $this->assertEquals($expected, $actual);
    }

    public function testJunctions()
    {
        $junctions = new Collection($this->backend->junctions);
        $actual = $junctions->orderBy('name')->select('name', null)->toArray();
        $expected = ['Membership', 'Rating'];
        $this->assertEquals($expected, $actual);
    }

    public function testOrder()
    {
        $config = $this->backend->configs['Order'];
        $this->assertEquals(['id'], $config->id, 'The primary key of the record is just the "id" column');
        $this->assertCount(0, $config->hasMany);
        $this->assertCount(1, $config->belongsTo, 'Detects the 1-to-many relation with the customer');
        $belongsToCustomer = $config->belongsTo['customer'];
        $this->assertEquals('customer_id', $belongsToCustomer['reference'], 'The foreign_key in orders table');
        $this->assertEquals('Customer', $belongsToCustomer['model'], 'The model the order belongs to');
        $this->assertEquals('id', $belongsToCustomer['id'], 'The column of the id in the customers table');
    }

    public function testGroup()
    {
        $config = $this->backend->configs['Group'];
        $this->assertCount(0, $config->belongsTo);
        $this->assertCount(2, $config->hasMany);
        $manyCustomers = $config->hasMany['customers'];

        $this->assertEquals('group_id', $manyCustomers['reference'], 'The foreign_key in groups table');
        $this->assertEquals('Customer', $manyCustomers['model'], 'The model the group has many of');
        $this->assertEquals('customer_id', $manyCustomers['id'], 'The column of the id in the customers table');
        $this->assertEquals('Membership', $manyCustomers['through'], 'The hasMany is a many2many relation with junction model/table memberships');
        $this->assertEquals([], $manyCustomers['fields'], 'The junction table has no additional fields');

        $manyRatings = $config->hasMany['ratings'];

        $this->assertEquals('group_id', $manyRatings['reference'], 'The foreign_key in groups table');
        $this->assertEquals('Customer', $manyRatings['model'], 'The model the group has many of');
        $this->assertEquals('customer_id', $manyRatings['id'], 'The column of the id in the customers table');
        $this->assertEquals('Rating', $manyRatings['through'], 'The hasMany is a many2many relation with junction model/table ratings');
        $this->assertEquals(['rating' => 'rating'], $manyRatings['fields'], 'The junction table has the rating as additional fields');
    }

    public function testCustomer()
    {
        $config = $this->backend->configs['Customer'];
        $this->assertCount(0, $config->belongsTo);
        $this->assertCount(3, $config->hasMany);
        
        $manyOrders = $config->hasMany['orders'];

        $this->assertEquals('customer_id', $manyOrders['reference'], 'The foreign_key in customers table');
        $this->assertEquals('Order', $manyOrders['model'], 'The model the group has many of');
        $this->assertEquals('customer', $manyOrders['belongsTo'], 'The property of connected model that references the customer table');

        $manyGroups = $config->hasMany['groups'];

        $this->assertEquals('customer_id', $manyGroups['reference'], 'The foreign_key in customers table');
        $this->assertEquals('Group', $manyGroups['model'], 'The model the group has many of');
//        $this->assertEquals('group_id', $manyGroups['id'], 'The column of the id in the groups table');
        $this->assertEquals('Membership', $manyGroups['through'], 'The hasMany is a many2many relation with junction model/table memberships');
        $this->assertEquals([], $manyGroups['fields'], 'The junction table no additional fields');
        
        $manyRatings = $config->hasMany['ratings'];

        $this->assertEquals('customer_id', $manyRatings['reference'], 'The foreign_key in customers table');
        $this->assertEquals('Group', $manyRatings['model'], 'The model the group has many of');
        $this->assertEquals('group_id', $manyRatings['id'], 'The column of the id in the groups table');
        $this->assertEquals('Rating', $manyRatings['through'], 'The hasMany is a many2many relation with junction model/table memberships');
        $this->assertEquals(['rating' => 'rating'], $manyRatings['fields'], 'The junction table has the rating as additional fields');
    }

    /**
     * Elke test_* met een schone database beginnen.
     */
    public function fillDatabase($db)
    {
        $db->import(dirname(__FILE__) . '/rebuild_test_database.' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . '.sql', $error);
        $this->backend = new DatabaseRepositoryBackend([$this->dbLink]);
    }
}
