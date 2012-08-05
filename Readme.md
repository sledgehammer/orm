
# Sledgehammer ORM

The Object-relational mapping (ORM) module for the Sledgehammer Framework.


## Features

* Full AutoCompletion support
* POCO support. The Repository can load Plain Old Class Objects (POCO's)
* Detect relations from the database
* A ActiveRecord frontend
* Linq style filtering support
* Support for complex property mapping. A column "city" can be mapped to property "address->city"
* 1 database record maps to only 1 instance.
* Support for multiple backends: PDO (MySQL, SQLite), Webservices (Twitter, etc)
* Clean queries. (No "1 = 1" where statements, etc)


## Usage

```php
// inside your application/init.php
$repo = getRepository();
$repo->registerBackend(new DatabaseRepositoryBackend("default")); // Extract models from the "default" database connection.

// Somewhere in your application
$repo = getRepository();
$customer = $repo->getCustomer($_GET['id]);
$customer->name = $_POST['name'];
$repo->saveCustomer($customer);

// When the Customer class extends Sledgehammer\ActiveRecord the familiar API is also available
$customer = Customer::find($_GET['id]);
$customer->name = $_POST['name'];
$customer->save();

// Linq style filtering
$selection = $repo->allCustomers()->where(array('name' => 'James Bond'))->where(function ($c) { return $c->isSpecialAgent(); });
$list = Customer::all()->select('name', 'id'); // Results in: array($id1 => $name1, $id2 => $name2, ...)
```

## Dictionary

Backend: A data retrieval and storing mechanism.
Model: A configuration to map backend data to instances.
Instance: An object that is created by the Repository from backend data based on a Model.