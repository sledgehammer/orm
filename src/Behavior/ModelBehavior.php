<?php

namespace Sledgehammer\Orm\Behavior;

use Sledgehammer\Orm\Backend\RepositoryBackend;
use Sledgehammer\Orm\ModelConfig;
use Traversable;

/**
 * Overwrite the behavior for models. Sits bewteen the RepositoryBackend and the Repository.
 */
class ModelBehavior extends RepositoryBackend
{
    /**
     * @var RepositoryBackend
     */
    public $backend;

    /**
     * Callback for when Repository->registerBehavior is called.
     * Allows modification of the config.
     *
     * @param ModelConfig $config
     */
    public function register($config)
    {
    }

    /**
     * Retrieve model-data by id.
     *
     * @return mixed data
     */
    public function get($id, $config)
    {
        return $this->backend->get($id, $config);
    }

    /**
     * Retrieve all available model-data.
     *
     * @param array $config
     *
     * @return Traversable|array
     */
    public function all($config)
    {
        return $this->backend->all($config);
    }

    /**
     * Retrieve all related model-data.
     *
     * @param array $relation The hasMany relation
     * @param mixed $id       The ID of the container instance.
     *
     * @return Traversable|array
     */
    public function related($config, $reference, $id)
    {
        return $this->backend->related($config, $reference, $id);
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
        return $this->backend->update($new, $old, $config);
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
        return $this->backend->add($data, $config);
    }

    /**
     * Permanently remove an record based on the data.
     *
     * @param array $data   Might only contain the ID
     * @param array $config
     */
    public function delete($data, $config)
    {
        return $this->backend->delete($data, $config);
    }
}
