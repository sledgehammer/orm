<?php

namespace Sledgehammer\Orm\Backend;

use Exception;
use Sledgehammer\Core\Base;
use Sledgehammer\Orm\ModelConfig;
use Traversable;

/**
 * The minimal interface for a (read-only) Repository Backend.
 */
abstract class RepositoryBackend extends Base
{
    /**
     * @var string
     */
    public $identifier;

    /**
     * The available models in this backend.
     *
     * @var ModelConfig[] array('Model name' => ModelConfig, 'Model2 name' => ModelConfig, ...)
     */
    public $configs;

    /**
     * The junction tables.
     *
     * @var array|ModelConfig
     */
    public $junctions = [];

    /**
     * Retrieve model-data by id.
     *
     * @return mixed data
     */
    abstract public function get($id, $config);

    /**
     * Retrieve all available model-data.
     *
     * @param array $config
     *
     * @return Traversable|array
     */
    public function all($config)
    {
        throw new Exception('Method: '.get_class($this).'->all() not implemented');
    }

    /**
     * Retrieve all related model-data.
     *
     * @return Traversable|array
     */
    public function related($config, $reference, $id)
    {
        return $this->all($config)->where([$reference => $id]);
    }

    /**
     * Update an existing record.
     *
     * @param mixed $new
     * @param mixed $old
     * @param array $config
     *
     * @return mixed updated data
     */
    public function update($new, $old, $config)
    {
        throw new Exception('Method: '.get_class($this).'->update() not implemented');
    }

    /**
     * Add a new record.
     *
     * @param mixed $data
     * @param array $config
     *
     * @return mixed
     */
    public function add($data, $config)
    {
        throw new Exception('Method: '.get_class($this).'->add() not implemented');
    }

    /**
     * Permanently remove an record based on the data.
     *
     * @param array $data   Might only contain the ID
     * @param array $config
     */
    public function delete($data, $config)
    {
        throw new Exception('Method: '.get_class($this).'->remove() not implemented');
    }

    /**
     * Rename a model and remap the relations to the new name.
     *
     * @param string $from Current name
     * @param string $to   The new name
     */
    public function renameModel($from, $to)
    {
        foreach ($this->configs as $config) {
            if ($config->name === $from) {
                $config->name = $to;
                $config->plural = null;
            }
            foreach ($config->belongsTo as $key => $belongsTo) {
                if ($belongsTo['model'] == $from) {
                    $config->belongsTo[$key]['model'] = $to;
                }
            }
            foreach ($config->hasMany as $key => $hasMany) {
                if ($hasMany['model'] == $from) {
                    $config->hasMany[$key]['model'] = $to;
                }
            }
        }
    }

    /**
     * Rename a property and remap the relations to the new name.
     *
     * @param string $model The modelname
     * @param string $from  The current propertyname
     * @param string $to    The new propertyname
     */
    public function renameProperty($model, $from, $to)
    {
        if (empty($this->configs[$model])) {
            throw new Exception('Unable to rename property, model "'.$model.'" not found');
        }
        $config = $this->configs[$model];
        if (in_array($to, $config->getPropertyNames())) {
            notice('Overwriting existing property "'.$to.'"');
            // Unset original mapping
            $column = array_search($to, $config->properties);
            if ($column !== false) {
                unset($config->properties[$column]);
            } else {
                unset($config->belongsTo[$to]);
                unset($config->hasMany[$to]);
            }
        }
        if (array_key_exists($from, $config->defaults)) {
            $config->defaults[$to] = $config->defaults[$from];
            unset($config->defaults[$from]);
        }
        $column = array_search($from, $config->properties);
        if ($column !== false) { // A property?
            $config->properties[$column] = $to;

            return;
        }
        if (isset($config->belongsTo[$from])) { // A belongsTo relation?
            $config->belongsTo[$to] = $config->belongsTo[$from];
            unset($config->belongsTo[$from]);
            if (isset($config->belongsTo[$to]['model']) && isset($this->configs[$config->belongsTo[$to]['model']])) {
                $belongsToModel = $this->configs[$config->belongsTo[$to]['model']];
                foreach ($belongsToModel->hasMany as $property => $hasMany) {
                    if ($hasMany['model'] === $model && $hasMany['belongsTo'] === $from) {
                        $belongsToModel->hasMany[$property]['belongsTo'] = $to;
                        break;
                    }
                }
            }

            return;
        }
        if (isset($config->hasMany[$from])) { // A hasMany relation?
            $config->hasMany[$to] = $config->hasMany[$from];
            unset($config->hasMany[$from]);

            return;
        }
        notice('Property: "'.$from.' not found"');
    }

    /**
     * Remove a property.
     *
     * @param string $model    The modelname
     * @param string $property The current propertyname
     */
    public function skipProperty($model, $property)
    {
        if (empty($this->configs[$model])) {
            throw new Exception('Unable to skip property, model "'.$model.'" not found');
        }
        $config = $this->configs[$model];
        $column = array_search($property, $config->properties);
        unset($config->defaults[$property]);
        if ($column !== false) { // A property?
            unset($config->properties[$column]);

            return;
        }
        if ($config->hasMany[$property]) {
            unset($config->hasMany[$property]);

            return;
        }
        if ($config->belongsTo[$property]) {
            unset($config->belongsTo[$property]);

            return;
        }
    }
}
