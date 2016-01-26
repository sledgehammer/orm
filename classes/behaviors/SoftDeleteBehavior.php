<?php

/**
 * SoftDeleteBehavior
 */

namespace Sledgehammer;

/**
 * Instead of deleting a record toggle a flag
 * @package ORM
 */
class SoftDeleteBehavior extends ModelBehavior {

    private $path;

    function __construct($path = 'is_deleted') {
        $this->path = $path;
    }

    /**
     * Remove the "is_deleted" property from the mapping.
     * @param ModelConfig $config
     */
    function register($config) {
        if (isset($config->properties[$this->path])) {
            unset($config->defaults[$config->properties[$this->path]]); // Remove from the default values
            unset($config->properties[$this->path]); // Remove property mapping
        }
        $config->ignoreProperties[] = $this->path;
    }

    /**
     * Retrieve non-deleted model-data by id.
     *
     * @return mixed data
     */
    function get($id, $config) {
        $data = $this->backend->get($id, $config);
        if (PropertyPath::get($this->path, $data)) {
            throw new \Exception('Record was deleted');
        }
        return $data;
    }

    /**
     * Retrieve all non-deleted model-data.
     *
     * @param array $config
     * @return Collection
     */
    function all($config) {
        return parent::all($config)->where(array(
                    $this->path => 0
        ));
    }

    function related($config, $reference, $id) {
        return parent::related($config, $reference, $id)->where(array(
                    $this->path => 0
        ));
    }

    /**
     * Instead of deleting set the flag.
     *
     * @param array $data
     * @param array $config
     */
    function delete($data, $config) {
        $old = $data;
        PropertyPath::set($this->path, 1, $data);
        parent::update($data, $old, $config);
    }

}

?>
