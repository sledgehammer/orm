<?php

namespace Sledgehammer\Orm;

use Sledgehammer\Core\Collection;

/**
 * An ActiveRecord with (generated) properties based on the ModelConfig->properties.
 * Adds the ActiveRecord interface to any Repository model, but disables support for property-autocompletion.
 */
class SimpleRecord extends ActiveRecord
{
    public function __set($property, $value)
    {
        if ($this->_state == 'constructed') {
            $this->$property = $value; // Add properties on the fly (in the construction fase)
        } else {
            return parent::__set($property, $value);
        }
    }

    /**
     * Find an instance based on critera.
     *
     * @param string $model
     * @param mixed  $conditions
     * @param bool \$allowNone  When no match is found, return null instead of throwing an Exception.
     * @param array $options array(
     *                       'repository' => (string) "default"
     *                       'preload' => (bool) false
     *                       )
     *
     * @return SimpleRecord
     */
    public static function one($model, $conditions = null, $allowNone = false, $options = [])
    {
        if (count(func_get_args()) < 2) {
            warning('SimpleRecord::find() requires minimal 2 parameters', 'SimpleRecord::find($model, $conditions, $options = []');
        }
        $options['model'] = $model;

        return parent::one($conditions, $allowNone, $options);
    }

    /**
     * @param string $model
     * @param array  $options
     *
     * @return Collection
     */
    public static function all($model = null, $options = [])
    {
        if (count(func_get_args()) < 1) {
            warning('SimpleRecord::all() requires minimal 1 parameter', 'SimpleRecord::all($model, $options = []');
        }
        $options['model'] = $model;

        return parent::all($options);
    }

    /**
     * @param string $model   (required)
     * @param array  $values
     * @param array  $options array(
     *                        'repository' => (string) "default"
     *                        )
     *
     * @return SimpleRecord
     */
    public static function create($model = null, $values = [], $options = [])
    {
        if (count(func_get_args()) < 2) {
            warning('SimpleRecord::create() requires minimal 1 parameter', 'SimpleRecord::create($model, $values = [], $options = []');
        }
        $options['model'] = $model;

        return parent::create($values, $options);
    }
}
