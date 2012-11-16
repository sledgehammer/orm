
# Sledgehammer ORM

The Object-relational mapping (ORM) module for the Sledgehammer Framework.


## Features

* Full AutoCompletion support
* POCO support. The Repository can load Plain Old Class Objects (POCO's)
* Detect relations from the database
* An optional ActiveRecord frontend
* Linq style filtering support
* Support for complex property mapping. A column "city" can be mapped to property "address->city"
* 1 database record maps to only 1 instance. via an [IdentityMap](http://martinfowler.com/eaaCatalog/identityMap.html)
* Relations are objects, To set the "customer_id" you'll set the "customer" property to a customer object.
* Cascading save(). A save will update & insert all connected records.
* Support for multiple backends: PDO (MySQL, SQLite), Webservices (Twitter, etc)
* Clean queries. (No "1 = 1" where statements, etc)


## Usage

```php
// inside your bootstrap.php
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

## Relations and Placeholders

The relations of objects are loaded on demand via placeholder classes.
Just use the object as if all the relations are already loaded:
```
$customer->orders[0]->product->title;
```
Works and provides autocompletion all the way.

A downside to the placeholder technique is that:
```
if ($customer->orders[0]->id === $order->id) { ... } // Always works

if ($customer->orders[0] === $order) { ...} // WRONG. Doesn't work reliably
```
This is because $order might still be a BelongsToHolder object that will be replaced when used (calling a function, getting or setting a property)
Sidenote: A BelongsToHolder often knows the id value and reading $order->id won't trigger a replacement/query.

### Update a relation
You wont find any "customer_id" properties in the objects, to change the "customer_id" you'll need to use a customer object.
```
$order->customer = $repo->getCustomer(1);
$repo->saveOrder($order);
```

Don't do this
```
$order->customer->id = 1; // WRONG
$repo->saveOrder($order);  // Throws Exception
```
This is because you're changing the customer object which the repository tries to save before the order and id changes aren't allowed.

## Dictionary

Backend: A data retrieval and storing mechanism.
Model: A configuration to map backend data to instances.
Instance: An object that is created by the Repository from backend data based on a Model.