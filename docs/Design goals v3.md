Design Goals ORM
------------------


## Scope / Duties

 * Retrieving data from a database/datasource and inject that data into an object.
   - List
   - Read
   - Create/Update
 * Protect againt SQL injection
 * OOP aproach to database records.
   - Easily fetch related records
 * Optimize for programmer happiness


## Looking back on previous version

The good:

 + protection against sql injection
 + Lazy relations
 + columns as properties (a object is a row in the db)
 + relations as properties
 + Only save/update the changes
 + config for the customer in the customer class (no boilerplate mapper classes or xml configurations)
 + A record (instance) object can't modify other records
 + Column/property validation (no silent dataloss)
 + Quick & dirty with SimpleRecord's auto-configuration
 + Strict: No first() method, find() returns 1 object or an exception
 + AutoCompletion [with the "new RecordClass($id)" syntax];

Shady:

 + Dual mode, a alternative/difficult concept.
 + Not POCO
 + AutoCompletion not aware of static/instance mode
 + No support for greedy relations

Bad:

 + Cryptic errors on incorrect configurations.
 + Low discoverabily of functionality / Difficult to configure.


## New technology available/discoved

 * Closures ($this support in 5.4)
 * Late static binding
 * Mixins (Traits support in PHP5.4?)


## The Silver Bullet

This chapter descibes the (unrealistic) properties of the ultimate ORM. "The one ORM to rule them all."

### The class should determines the structure

The 'city' column in the 'customers' table maps to $customer->address->city.

### AutoCompletion

Full support for IDE autocompletion (implemented)

### Only one instance per row

$x = new Customer(1);
$y = new Customer(1); // $y becomes a reference to $x;
(Implemented as $repository->getCustomer(1), the "new" keyword will allway generate a new/separate instance)

### POCO support

ORM can save and retrieves Plain Old Class Objects.

### No configuration

ORM should infer entities from the database model.

### Clean/short/readable SQL statements

No quotes around integers for ids.
Use "*" to select all columns.
Uses joins when appropriate.

### No performance penalty

High performance should be possible.

### Autosaving
Add the option to save all unsaved changes.

### SQL

Don't depend on SQL, but allow it.
Alternative datasources can't support sql.
Allowing SQL for the datasources that can support it is powerfull (great featureset with great performance).

### Supports for multiple backends

$player->tweets
player from the database, tweets from the twitter API.



## Concepts


### POCO (Plain Old Class Objects)

The save() / delete() methods don't make sense on all (record) objects.
Also no inheritance of interface required.

No direct connection between datasource and object.
The same class can be used for multiple database/repository connections.



## Known limitations
Late Static Binding: aka Customer::find() uses the ActiveRecord::find() implementation.
LSB has limited IDE autocompletion. No "@return @sameClass" phpdoc available



## Implementation idea's

### Improved mapping
 xpath notation for mapping notation:
 column "city" to property "address/city" translates to $x->address->city; // or "address.city"? or "address->city"?
 // is 'address' a array, stdClass, ArrayObject or custom class?
 // Getting autocompletion to work: @var AddresStruct public $address

Became "address->city" for objects and "address[city]" for arrays in PropertyPath.

### Repository

Loading and Saving logic outside the Record object.
Aka a Repository link instead of an db_link
Repository extracts configuration from the record class but allows you to redefine those values a.k.a use a test database.

### Fake classes

Generate fake phpClasses in the tmp/ folder to enable autocompletion for dynamic methods like Repository->findCustomer(1)
/**
 * @return /AutoCompletionHelper/Repository
function getRepository() {
}


### Auto)Generated RepositoryClass
Also generate the function body and use this class the next run.


### Table API 

To automate controllers like the JsonCrudController
// CRUD
$repo->get($model,$id);
$repo->all($model, $criteria);
$repo->add($o); //insert
$repo->update($o);
$repo->delete($id);
$repo->deleteAll($criteria); // Won't be implemented, would make big mistakes in client code a one-liner.
// Filtering/List
$rs = $repo->all();
$rs = $repo->all()->where('x = 2')->offset(10)->limit(10);
foreach($rs as $record) {}


### Repository API

$repository = getRepository();
$customer = $repository->getCustomer($id);
$customer = $repository->findCustomer($criteria);
  workaround $customer = $repository->allCustomers()->where($criteria)->offsetGet(0);
    but both options wouldn't force 1 result (or an Exception).

$repository->saveCustomer($customer);

### ActiveRecord API

MyRecord extends ActiveRecord

MyRecord::find()
MyRecord::all()
MyRecord->save()
MyRecord->delete()
(private)MyRecord->_state = 'NEW'|'EXISTING'|'DELETED'
MyRecord::$repository;

@property $_recordConfig => array('repository' => 'default', 'table' => 'customer', 'hasMany' => array(), 'belongsTo' => array())
or
@return RecordConfig
Record->getRecordConfig()

### SimpleRecord
SimpleRecord is an ActiveRecord that can be used for all tables. It "imports" the properties from the Repository


## Issues
injecting values into private properties (with data from the db)
  Inject via an "SH/Record/PrivateProperties" interface? setPrivateProperties($values)
  Caller should be checked/validated?

injecting lazy-loaded relations.


## Rejected ideas

Autogenerate repositories based on database connection(s)
'default' => new DbRepo(getDatabase('default'));
Because: one Repository instance can handle multiple backends/database connections/schema's and the default database might need additional tweaks.