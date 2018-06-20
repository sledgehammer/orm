<?php

namespace Sledgehammer\Orm;

use Sledgehammer\Core\Base;
use ArrayAccess;

/**
 * A entry in a many-to-many relation where the link/bridge table has additional fields.
 * Behaves as the linked object, but with additional properties.
 */
class Junction extends Base implements ArrayAccess
{
    /**
     * The object this junction links to.
     *
     * @var object
     */
    protected $instance;

    /**
     * The additional fields in the relation.
     *
     * @var array =>
     */
    protected $fields;

    /**
     * Allow new properties to be added to $this->fields.
     *
     * @var bool
     */
    protected $dynamicFields;

    /**
     * Constructor.
     *
     * @param object $instance           The instance to link to.
     * @param array  $fields             The additional fields in the relation.
     * @param bool   $noAdditionalFields The $fields parameter contains all fields for this junction.
     */
    public function __construct($instance, $fields = [], $noAdditionalFields = false)
    {
        $this->instance = $instance;
        $this->fields = $fields;
        $this->dynamicFields = !$noAdditionalFields;
    }

    /**
     * Get a property or fields value.
     *
     * @param string $property
     *
     * @return mixed
     */
    public function __get($property)
    {
        if (property_exists($this->instance, $property)) {
            if (array_key_exists($property, $this->fields)) {
                notice('Property "'.$property.'" is ambiguous. It\'s available in both the instance as the junction fields.', "To modify the mapping of the junction field change the value of \$ModelConfig->hasMany[\$relation]['fields']['".$property."']");
            }

            return $this->instance->$property;
        }
        if (array_key_exists($property, $this->fields)) {
            return $this->fields[$property];
        }
        if ($this->dynamicFields) {
            $this->fields[$property] = null;

            return;
        }
        $properties = \Sledgehammer\reflect_properties($this->instance);
        $properties['public'] = array_merge($properties['public'], $this->fields);
        warning('Property "'.$property.'" doesn\'t exist in a '.get_class($this).' ('.get_class($this->instance).') object', \Sledgehammer\build_properties_hint($properties));
    }

    /**
     * Set a property or fields.
     *
     * @param string $property
     * @param mixed  $value
     */
    public function __set($property, $value)
    {
        if (property_exists($this->instance, $property)) {
            if (array_key_exists($property, $this->fields)) {
                notice('Property "'.$property.'" is ambiguous. It\'s available in both the instance as the junction fields.', "To modify the mapping of the junction field change the value of \$ModelConfig->hasMany[\$relation]['fields']['".$property."']");
            }
            $this->instance->$property = $value;

            return;
        }
        if (array_key_exists($property, $this->fields) || $this->dynamicFields) {
            $this->fields[$property] = $value;

            return;
        }
        parent::__set($property, $value);
    }

    /**
     * Pass all methods to the linked $instance.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return call_user_func_array([$this->instance, $method], $arguments);
    }
    public function offsetExists($index)
    {
        if ($this->instance instanceof ArrayAccess) {
            return $this->instance->offsetExists($index);
        }
        trigger_error('Cannot use object of type '.get_class($this->instance).' as array', E_USER_ERROR);
    }

    public function offsetGet($index)
    {
        if ($this->instance instanceof ArrayAccess) {
            return $this->instance->offsetGet($index);
        }
        trigger_error('Cannot use object of type '.get_class($this->instance).' as array', E_USER_ERROR);
    }

    public function offsetSet($index, $value)
    {
        if ($this->instance instanceof ArrayAccess) {
            return $this->instance->offsetSet($index, $value);
        }
        trigger_error('Cannot use object of type '.get_class($this->instance).' as array', E_USER_ERROR);
    }
    public function offsetUnset($index)
    {
        if ($this->instance instanceof ArrayAccess) {
            return $this->instance->offsetUnset($index);
        }
        trigger_error('Cannot use object of type '.get_class($this->instance).' as array', E_USER_ERROR);
    }
}
