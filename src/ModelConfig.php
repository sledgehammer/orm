<?php

namespace Sledgehammer\Orm;

use Sledgehammer\Core\Object;

/**
 * Model configuration class, a formal definition of a Repository model.
 */
class ModelConfig extends Object
{
    /**
     * The name of the model.
     *
     * @var string
     */
    public $name;

    /**
     * The name of the model in plural from.
     *
     * @var string
     */
    public $plural;

    /**
     * The full classname (null: AutoDetect class, false: Autogenerate class).
     *
     * @var string|null|false
     */
    public $class;

    /**
     * Direct mapping of properties to the backend data structure. array($column => $property).
     *
     * @var array
     */
    public $properties = [];

    /**
     * The element(s) in the backend data that identifies an instance. Example: array('id') for the 'id' field.
     *
     * @var array
     */
    public $id = [];

    /**
     * Configuration of the belongsTo relation(s).
     *
     * @var array array(
     *            $property => array(
     *            'reference' => $column,  // foreign_key: "product_id"
     *            'model' => $modelName,   // The foreign model: "Product"
     *            'id' => $idColumn,       // (optional) The id: "id"
     *            ,)
     *            $property => array(
     *            'convert' => $column,  // column with data: array('id' => 1,  'name' => 'James Bond')
     *            'model' => $modelName, // The model: "Hero"
     *            )
     *            )
     */
    public $belongsTo = [];

    /**
     * Configuration of the hasMany relation(s)
     * Contains both one-to-many and many-to-many relations.
     *
     * @var array array(
     *            $property => array(
     *            'model' => $modelName, // The foreign model: "Product"
     *            'reference' => $column, // foreign_key to this container instance.
     *            'belongsTo' => $propertyPath, // (optional) The belongsTo property in the related instances in a one-to-many relation that refers back to the container instance. Used in save() for implicitly setting the foreignkey value.
     *            'through' => $junctionName, // (optional) The junction for many-to-many relations.
     *            'junctionClass' => $fullclassname, // (optional) The junctionClass to use (defaults to the Sledgehammer\Junction)
     *            'fields' => array($column => $junctionProperty), // (optional) Mapping for the additional fields in a junction (many-to-many with fields)
     *            'id' => $column, // (optional) foreign_key for the related model in the many-to-many table: "product_id"
     *            'conditions' => [] // (optional) Additional extra (static) conditions
     *            )
     *            )
     */
    public $hasMany = [];

    /**
     * Default values for new instance.
     *
     * @var array 'property(PropertyPath)' => default value
     */
    public $defaults = [];

    /**
     * Filter the data from the backend before using setting the values in the instance.
     *
     * @var array 'column(PropertyPath)' => filter(callable)
     */
    public $readFilters = [];

    /**
     * Filter the property values before writing the data to the backend.
     *
     * @var array 'column(PropertyPath)' => filter(callable)
     */
    public $writeFilters = [];

    /**
     * The identfier of the backend this config belongs to.
     *
     * @var string
     */
    public $backend;

    /**
     * An container for RepositoryBackend specific settings.
     * This is the config that is passed to create, read, update and delete functions in the backend.
     *
     * @var mixed
     */
    public $backendConfig;

    /**
     * A whitelist of (public) properties that won't be listed as missing when validation the class.
     *
     * @var array
     */
    public $ignoreProperties = [];

    /**
     * Constructor.
     *
     * @param string $name    Model name
     * @param array  $options Additional configuration options
     */
    public function __construct($name, $options = [])
    {
        $this->name = $name;
        foreach ($options as $property => $value) {
            $this->$property = $value;
        }
    }

    /**
     * Return all property-paths.
     *
     * @return array
     */
    public function getPropertyNames()
    {
        return array_merge(array_values($this->properties), array_keys($this->belongsTo), array_keys($this->hasMany));
    }
}
