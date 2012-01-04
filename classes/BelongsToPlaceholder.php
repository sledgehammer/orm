<?php
/**
 * This Placeholder facilitates lazy loading of belongsTo relations.
 * A BelongsToPlaceholder object behaves like the object from the repository, but only retrieves the real object on-access or on-change.
 *
 * @package ORM
 */
namespace SledgeHammer;

class BelongsToPlaceholder extends Object {

	/**
	 * @var array
	 */
	private $__fields;

	/**
	 * @var string  repository/model/property
	 */
	private $__reference;

	/**
	 * @var stdClass  The instance this placeholder belongs to
	 */
	private $__container;

	function __construct($reference, $container, $fields = array()) {
		$this->__reference = $reference;
		$this->__container = $container;
		$this->__fields = $fields;
	}

	function __get($property) {
		if (array_key_exists($property, $this->__fields)) {
			return $this->__fields[$property]; // ->id
		}
		return $this->__replacePlaceholder()->$property;
	}

	function __set($property, $value) {
		$this->__replacePlaceholder()->$property = $value;
	}

	function __call($method, $arguments) {
		return call_user_func_array(array($this->__replacePlaceholder(), $method), $arguments);
	}

	/**
	 * Replace the placeholder and return the real object.
	 * 
	 * @return Object
	 */
	private function __replacePlaceholder() {
		$parts = explode('/', $this->__reference);
		$repositoryId = array_shift($parts);
		$model = array_shift($parts);
		$property = implode('/', $parts);
		$self = PropertyPath::get($this->__container, $property);
		if ($self !== $this) {
			notice('This placeholder belongs to an other (cloned?) container');
			return $self;
		}
		$repo = getRepository($repositoryId);
		$repo->loadAssociation($model, $this->__container, $property);
		return PropertyPath::get($this->__container, $property);
	}

}

?>