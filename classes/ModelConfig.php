<?php
/**
 * ModelConfig
 */
namespace Sledgehammer;
/**
 * Model configuration class, a formal definition of a Repository model.
 *
 * @package ORM
 */
class ModelConfig extends Object {

	/**
	 * The name of the model
	 * @var string
	 */
	public $name;

	/**
	 * The name of the model in plural from.
	 * @var string
	 */
	public $plural;

	/**
	 * The full classname (null: AutoDetect class, false: Autogenerate class)
	 * @var string|null|false
	 */
	public $class;

	/**
	 * Direct mapping of properties to the backend data structure. array($column => $property)
	 * @var array
	 */
	public $properties = array();

	/**
	 * The element(s) in the backend data that identifies an instance. Example: array('id') for the 'id' field.
	 * @var array
	 */
	public $id = array();

	/**
	 * Configuration of the belongsTo relation(s)
	 * @var array  array(
	 *   $property => array(
	 *      'reference' => $column  // foreign_key: "product_id"
	 *      'model' => $modelName   // The foreign model: "Product"
	 *      'id' => $idColumn (optional) // The id: "id"
	 *   ,)
	 *   $property => array(
	 *      'convert' => $column  // column with data: array('id' => 1,  'name' => 'James Bond')
	 *      'model' => $modelName // The model: "Hero"
	 *   )
	 * )
	 */
	public $belongsTo = array();

	/**
	 * Configuration of the hasMany relation(s)
	 * @var array  array(
	 *   $property => array(
	 *     'model' => $modelName // The foreign model: "Product"
	 *     'reference' => $column, // foreign_key to thix countainer instance.
	 *     'belongsTo' => $propertyPath, // (optional) The belongsTo in the related instances in a on-to-many relation that refers back to the container instance. Used in save() for implicitly setting the foreignkey value.
	 *     'through' => $junctionName // (optional) The junction for many-to-many relations.
	 *     'id' => $column // (optional) foreign_key for the related model in the many-to-many table: "product_id"
	 *     'conditions' => array() // (optional) Additional extra (static) conditions
	 *   ),
	 * )
	 */
	public $hasMany = array();

	/**
	 * Default values for new instance.
	 * @var array
	 */
	public $defaults = array();

	/**
	 * @var string
	 */
	public $backend;

	/**
	 * An container for RepositoryBackend specific settings.
	 * @var mixed
	 */
	public $backendConfig;

	/**
	 * A whitelist of (public) properties that won't be listed as missing when validation the class.
	 * @var array
	 */
	public $ignoreProperties = array();

	/**
	 * Constructor
	 * @param string $name  Model name
	 * @param array $options  Additional configuration options
	 */
	function __construct($name, $options = array()) {
		$this->name = $name;
		foreach ($options as $property => $value) {
			$this->$property = $value;
		}
	}

}

?>
