<?php
/**
 * Junction
 */
namespace Sledgehammer;
/**
 * A entry in a many-to-many relation where the link/bridge table has additional fields.
 * Behaves as the linked object, but with additional properties.
 */
class Junction extends Object {

	/**
	 * The object this junction links to.
	 * @var object
	 */
	private $instance;

	/**
	 * The additional fields in the relation.
	 * @var array $field => $value
	 */
	private $fields;

	/**
	 * When true, only properties that exist in $fields are available.
	 * When false: Non existing properties are added to the $fields
	 * @var bool
	 */
	private $validate;

	/**
	 * Constructor
	 * @param object $instance
	 * @param array $fields
	 * @param bool $validate
	 */
	function __construct($instance, $fields = array(), $validate = false) {
		$this->instance = $instance;
		$this->fields = $fields;
		$this->validate = $validate;
	}

	/**
	 * Get a property or fields value.
	 *
	 * @param string $property
	 * @return mixed
	 */
	function __get($property) {
		if (property_exists($this->instance, $property)) {
			return $this->instance->$property;
		}
		if (array_key_exists($property, $this->fields)) {
			return $this->fields[$property];
		}
		if ($this->validate) {
			return parent::__get($property);
		}
		return null;
	}

	/**
	 * Set a property or fields.
	 *
	 * @param string $property
	 * @param mixed $value
	 */
	function __set($property, $value) {
		if (property_exists($this->instance, $property)) {
			$this->instance->$property = $value;
			return;
		}
		if (array_key_exists($property, $this->fields) || $this->validate === false) {
			$this->fields[$property] = $value;
			return;
		}
		parent::__set($property, $value);
	}

	/**
	 * Pass all methods to the linked $instance
	 *
	 * @param string $method
	 * @param array $arguments
	 * @return mixed
	 */
	function __call($method, $arguments) {
		return call_user_func_array(array($this->instance, $method), $arguments);
	}

}

?>