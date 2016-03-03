<?php

namespace SledgehammerTests\Orm;

use PDO;
use Sledgehammer\Core\Collection;
use Sledgehammer\Orm\Backend\DatabaseRepositoryBackend;
use Sledgehammer\Orm\Repository;
use SledgehammerTests\Core\DatabaseTestCase;

class DatabaseRepositoryBackendTest extends DatabaseTestCase
{

    protected $skipRebuildDatabase = true;

    /**
     * @var DatabaseRepositoryBackend
     */
    private $backend;

    function test_configs()
    {
        $configs = new Collection($this->backend->configs);
        $actual = $configs->orderBy('name')->select('name', null)->toArray();
        $expected = ['Customer', 'Group', 'Order'];
        $this->assertEquals($expected, $actual);
    }

    function test_junctions()
    {
        $junctions = new Collection($this->backend->junctions);
        $actual = $junctions->orderBy('name')->select('name', null)->toArray();
        $expected = ['Membership', 'Rating'];
        $this->assertEquals($expected, $actual);
    }

    function test_Order()
    {
        $config = $this->backend->configs['Order'];
        $this->assertCount(0, $config->hasMany);
        $this->assertCount(1, $config->belongsTo);
        $belongsToCustomer = $config->belongsTo['customer'];

        $this->assertEquals('customer_id', $belongsToCustomer['reference'], 'The foreign_key in orders table');
        $this->assertEquals('Customer', $belongsToCustomer['model'], 'The model the order belongs to');
        $this->assertEquals('id', $belongsToCustomer['id'], 'The column of the id in the customers table');
    }

    function test_Group()
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
        $this->assertEquals('Rating', $manyRatings['through'], 'The hasMany is a many2many relation with junction model/table memberships');
        $this->assertEquals(['rating' => 'rating'], $manyRatings['fields'], 'The junction table has the rating as additional fields');
    }

    /**
     * Elke test_* met een schone database beginnen.
     */
    public function fillDatabase($db)
    {
        $db->import(dirname(__FILE__) . '/rebuild_test_database.' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . '.sql', $error);
        $repo = new Repository();
        Repository::$instances[__CLASS__] = $repo;
        $this->backend = new DatabaseRepositoryBackend(array($this->dbLink));
    }

}
