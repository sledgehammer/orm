<?php

namespace Sledgehammer\Orm;

use Exception;
use Sledgehammer\Core\Collection;
use Sledgehammer\Core\Base;
use Sledgehammer\Core\EventEmitter;

/**
 * An ActiveRecord frontend for the Repository.
 */
abstract class ActiveRecord extends Base
{
    use EventEmitter;
    /**
     * The model in the repository, usually the same as the classname.
     *
     * @var string
     */
    protected $_model;

    /**
     * Persistance state:
     *   loaded: object was loaded from the persistance layer.
     *   new: object created, but not written to the persistance layer.
     *   saved: object has been saved to the persistance layer.
     *   deleted: object is deleted from the persistance layer.
     *
     * @var enum
     */
    protected $_state = 'unconstructed';

    /**
     * The repository this instance belongs to.
     *
     * @var string|Repository
     */
    protected $_repository;

    /**
     * The events/listeners.
     *
     * @var array
     */
    protected $events = [
        'create' => [], // When a new instance is created (with initial data), but commited to the backend.
        'load' => [], // After the data from the backend is injected into the ActiveRecord.
        'saving' => [], // Before the data is sent to the backend
        'saved' => [], // After the data is sent to the backend
        'deleting' => [], // Before the delete operation is sent to the backend
        'deleted' => [], // When the Record is deleted
    ];

    /**
     * Use the repostory or static functions to create an instance.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $this->_state = 'constructed';
        if ($this->_model === null) {
            $this->_model = static::_getModel();
        }
        if (count(func_get_args()) != 0) {
            $model = $this->_model;
            throw new Exception('Parameters not allowed for "new '.get_class($this).'()", use $'.strtolower($model).' = repository->get'.$model.'($id); or  $'.strtolower($model).' = '.$model.'::find($id)');
        }
    }

    /**
     * Create a new instance.
     *
     * @param string $model
     * @param array  $values  (optional) Overwrite the default values.
     * @param array  $options array(
     *                        'repository' => string (optional) Overwrite the repository.
     *                        'model' => string (optional) Overwrite the model name.
     *                        )
     *
     * @return ActiveRecord
     */
    public static function create($values = [], $options = [])
    {
        $model = static::_getModel($options);
        $repo = static::_getRepostory($options);
        $instance = $repo->create($model, $values);
        if (get_class($instance) != get_called_class()) {
            throw new Exception('Model "'.$model.'"('.get_class($instance).') isn\'t configured as "'.get_called_class());
        }

        return $instance;
    }

    /**
     * Find an instance based on critera.
     *
     * Usage:
     *   ActiveRecordSubclass::one(1)
     *   ActiveRecordSubclass::one(array('id' => 2));
     *
     * When the critera matches more than 1 instance an exception is thrown.
     *
     * @param int|string|array $conditions
     * @param bool \$allowNone  When no match is found, return null instead of throwing an Exception.
     * @param array $options array(
     *                       'repository' => string (optional) Overwrite the repository.
     *                       'model' => string (optional) Overwrite the model name.
     *                       )
     *
     * @return ActiveRecord
     */
    public static function one($conditions, $allowNone = false, $options = [])
    {
        $model = static::_getModel($options);
        $repositoryId = static::_getRepostory($options);
        $repo = Repository::instance($repositoryId);
        if (is_scalar($conditions)) {
            if ($allowNone) {
                try {
                    $instance = $repo->get($model, $conditions);
                } catch (Exception $e) {
                    $instance = null;
                }
            } else {
                $instance = $repo->get($model, $conditions);
            }
        } else {
            $instance = $repo->one($model, $conditions, $allowNone);
        }
        if ($instance !== null && get_class($instance) != get_called_class()) {
            throw new Exception('Model "'.$model.'"('.get_class($instance).') isn\'t configured as "'.get_called_class());
        }

        return $instance;
    }

    /**
     * Retrieve a collection.
     *
     * @param array $options array(
     *                       'repository' => string (optional) Overwrite the repository.
     *                       'model' => string (optional) Overwrite the model name.
     *                       )
     *
     * @return Collection|ActiveRecord
     */
    public static function all($options = [])
    {
        $model = static::_getModel($options);
        $repositoryId = static::_getRepostory($options);
        $repo = Repository::instance($repositoryId);

        return $repo->all($model);
    }

    /**
     * Write the state to the persistance layer.
     *
     * @throws Exception
     */
    public function save($options = [])
    {
        if ($this->_state == 'deleted') {
            throw new Exception(get_class($this).'->save() not allowed on deleted objects');
        }
        $repo = Repository::instance($this->_repository);

        return $repo->save($this->_model, $this, $options);
    }

    /**
     * Delete the instance from the persistance layer.
     */
    public function delete()
    {
        $repo = Repository::instance($this->_repository);

        return $repo->delete($this->_model, $this);
    }

    /**
     * Retrieve unsaved changes.
     *
     * @return array
     */
    public function getChanges()
    {
        $repo = Repository::instance($this->_repository);

        return $repo->diff($this->_model, $this);
    }

    public function __get($property)
    {
        if ($this->_state == 'deleted') {
            notice('A deleted Record has no properties');

            return;
        }
        return parent::__get($property);
    }

    public function __set($property, $value)
    {
        if ($this->_state == 'deleted') {
            notice('A deleted Record has no properties');
        } else {
            return parent::__set($property, $value);
        }
    }

    /**
     * Detect the modelname.
     *
     * Checks the $options array.
     * Checks if the $_model property is defined in the subclass.
     * Or defaults to the classname.
     *
     * @param array $options
     *
     * @return string
     */
    protected static function _getModel($options = [])
    {
        if (isset($options['model'])) {
            // Use the model given in the parameters
            return $options['model'];
        }
        $class = get_called_class();
        $properties = get_class_vars($class);
        if ($properties['_model'] !== null) {
            return $properties['_model'];
        }
        // Detect modelname based on the classname
        $parts = explode('\\', $class);

        return array_pop($parts);
    }

    /**
     * Detect the repository.
     *
     * Checks the $options array.
     * Checks if the $_repository property is defined in the subclass.
     * Or uses the default repository.
     *
     * @param array $options
     *
     * @return Repository
     */
    protected static function _getRepostory($options = [])
    {
        if (isset($options['repository'])) {
            // Use the repository given in the parameters
            return Repository::instance($options['repository']);
        }
        $class = get_called_class();
        $properties = get_class_vars($class);
        if ($properties['_repository'] !== null) {
            // Use the repository defined in de subclass
            return Repository::instance($properties['_repository']);
        }

        return Repository::instance(); // Use the default repository
    }

    /**
     * Handle the 'create' event.
     *
     * @param object $sender
     * @param array  $options
     */
    protected function onCreate($sender, $options)
    {
        if (isset($options['repository'])) {
            $this->_repository = $options['repository'];
        }
        if (isset($options['model'])) {
            $this->_model = $options['model'];
        }
        $this->_state = 'new';
    }

    /**
     * Handle the 'load' event.
     *
     * @param object $sender
     * @param array  $options
     */
    protected function onLoad($sender, $options)
    {
        if (isset($options['repository'])) {
            $this->_repository = $options['repository'];
        }
        if (isset($options['model'])) {
            $this->_model = $options['model'];
        }
        $this->_state = 'loaded';
    }

    /**
     * Handle the 'saved' event.
     *
     * @param object $sender
     * @param array  $options
     */
    protected function onSaved()
    {
        $this->_state = 'saved';
    }

    /**
     * Handle the 'deleted' event.
     *
     * @param object $sender
     * @param array  $options
     */
    protected function onDeleted()
    {
        $this->_state = 'deleted';
    }
}
