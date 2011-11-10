<?php
/**
 * Model configuration class, a formal definition of a Repository model.
 *
 *
 * @package Record
 */
namespace SledgeHammer;
class ModelConfig extends Object {

	/**
	 * @var string  The name of the model
	 */
	public $name;
	/**
	 *
	 * @var string  The name of the model in plural from.
	 */
	public $plural;
	/**
	 * @var string|null|false  The full classname (null: AutoDetect class, false: Autogenerate class)
	 */
	public $class;
	/**
	 * @var array  Direct mapping of properties to the backend data structure
	 */
	public $properties = array();
	/**
	 * @var array  The element(s) in the backend data that identifies an instance
	 */
	public $id = array('id');
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
	 *     'model' => $modelName
	 *     'property' => $property // The belongsTo property that referrer back
	 *     'id' => $id             // The id property of this model
	 *   )
	 * )
	 */
	public $hasMany = array();
	/**
	 * @var array  Default values for new instance
	 */
	public $defaults = array();

	/**
	 * @var string
	 */
	public $backend;
	/**
	 * @var mixed  An container for RepositoryBackend specific settings.
	 */
	public $backendConfig;

	/**
	 *
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
