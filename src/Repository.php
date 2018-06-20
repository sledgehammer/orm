<?php

namespace Sledgehammer\Orm;

use Exception;
use Sledgehammer\Core\Collection;
use Sledgehammer\Core\InfoException;
use Sledgehammer\Core\Base;
use Sledgehammer\Core\PropertyPath;
use Sledgehammer\Core\Singleton;
use Sledgehammer\Orm\Backend\RepositoryBackend;
use Sledgehammer\Orm\Behavior\ModelBehavior;
use stdClass;
use Traversable;

/**
 * Repository/DataMapper.
 *
 * An API to retrieve and store models from their backends and track their changes.
 * A model is a object oriented interface on top of the data the backend provides.
 *
 * @method \Generated\DefaultRepository|Repository instance(string $id)
 */
class Repository extends Base
{
    use Singleton;

    /**
     * Write php files for editor autocompletion.
     * must end with DIRECTORY_SEPARATOR
     * @var string|false
     */
    public static $autoCompleteFolder = false;

    /**
     * Registered namespaces that are searched for a classname that matches the model->name.
     *
     * @var array
     */
    protected $namespaces = [];

    /**
     * Registered models.
     *
     * @var array|ModelConfig [model => config]
     */
    protected $configs = [];

    /**
     * Registered junctions (many-to-many).
     *
     * @var array|ModelConfig
     */
    protected $junctions = [];

    /**
     * References to instances.
     *
     * @var array
     */
    protected $objects = [];

    /**
     * Mapping of plural notation to singular.
     *
     * @var array [$plural => $singular]
     */
    protected $plurals = [];

    /**
     * References to instances that are not yet added to the backend.
     *
     * @var array
     */
    protected $created = [];

    /**
     * References to instances that have been deleted.
     *
     * @var array
     */
    protected $deleted = [];

    /**
     * Registered backends.
     *
     * @var RepositoryBackend[]
     */
    protected $backends = [];

    /**
     * The configurations of the previously generated AutoComplete Helper classes.
     * Used to determine if a model has changed.
     *
     * @var array
     */
    private $autoComplete;

    /**
     * Used to speedup the execution RepostoryCollection->where() statements. (allows db WHERE statements).
     *
     * @var array [$model => [$propertyPath =>]]
     */
    private $collectionMappings = [];

    /**
     * Array containing instances that are loading saving/saved in 1 Repository->get() call.
     * Used for preventing infinite preloading.
     *
     * @var array
     */
    private $loading = [];

    /**
     * Array containing instances that are saving/saved in 1 Repository->save() call.
     * Used for preventing duplicate saves.
     *
     * @var array
     */
    private $saving = [];

    /**
     * Models which class is validated.
     *   $model => (bool) $valid.
     *
     * @var array
     */
    private $validated = [];
    
    /**
     * Registered closures that configure the default repository.
     * @var Closure[]
     */
    private static $lazyConfigurations = [];

    /**
     * Handle get$Model(), find$Model(), all$Models(), save$Model(), create$Model() and delete$Model() methods.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if (preg_match('/^(get|one|all|save|create|delete|reload)(.+)$/', $method, $matches)) {
            $method = $matches[1];
            array_unshift($arguments, $matches[2]);
            $usePlural = ($method === 'all');
            if ($method === 'reload') {
                if (count($arguments) == 1) {
                    $usePlural = true;
                    $arguments[] = null;
                    $arguments[] = ['all' => true];
                } elseif (count($arguments) == 2 && is_array($arguments[1]) && isset($this->plurals[$arguments[0]]) && $this->plurals[$arguments[0]] != $matches[2]) {
                    // reloadPlural($options)
                    $arguments[0] = $this->plurals[$arguments[0]];
                    $arguments[2] = $arguments[1];
                    $arguments[1] = null;
                    $arguments[2]['all'] = true;
                }
            }
            if ($usePlural) {
                if (isset($this->plurals[$arguments[0]])) {
                    $arguments[0] = $this->plurals[$arguments[0]];
                } else {
                    if (isset($this->configs[$arguments[0]])) {
                        warning('Use plural form "'.array_search($arguments[0], $this->plurals).'"');
                    }
                }
            }

            return call_user_func_array([$this, $method], $arguments);
        }

        return parent::__call($method, $arguments);
    }

    /**
     * Retrieve an instance from the Repository.
     *
     * @param string $model
     * @param mixed  $id      The instance ID
     * @param array  $options
     *                        'preload' => int  The preload recursion level.
     *                        false or 0: Only the the relation.
     *                        1: Also the relations of the relation.
     *                        2: Also the relations of the relations of the relation.
     *                        N: Etc.
     *                        true or -1: Load all relations of all relations.
     *
     * @return object instance
     */
    public function get($model, $id, $options = [])
    {
        $config = $this->_getConfig($model);
        $index = $this->resolveIndex($id, $config);
        if (isset($this->objects[$model][$index])) {
            $instance = $this->objects[$model][$index]['instance'];
        } else {
            $this->objects[$model][$index] = [
                'state' => 'retrieving',
                'instance' => null,
                'data' => null,
            ];
            try {
                $data = $this->_getBackend($config->backend)->get($id, $config->backendConfig);
                if (!is_array($data) && !is_object($data)) {
                    throw new InfoException('Invalid response from backend: "'.$config->backend.'"', ['Response' => $data]);
                }
            } catch (Exception $e) {
                unset($this->objects[$model][$index]);
                throw $e;
            }
            $indexFromData = $this->resolveIndex($data, $config);
            if ($index != $indexFromData) {
                unset($this->objects[$model][$index]); // cleanup invalid entry
                throw new Exception('The $id parameter doesn\'t match the retrieved data. '.$index.' != '.$indexFromData);
            }
            $this->objects[$model][$index]['data'] = $data;
            $this->objects[$model][$index]['state'] = 'retrieved';
            $instance = $this->convertToInstance($data, $config, $index);
            $this->objects[$model][$index]['instance'] = $instance;
        }
        if (isset($options['preload']) && $options['preload'] != 0) {
            $options['model'] = $model;
            --$options['preload'];
            $this->resolveProperties($this->objects[$model][$index]['instance'], $options);
        }

        return $instance;
    }

    /**
     * Retrieve an instance from the Repository based on criteria other than the id.
     * If the id is known use the Repository->get($model, $id) instead.
     *
     * When the critera matches 0 or more than 1 instances an exception is thrown, unless 'allow_none' is given as an option.
     *
     * @param string $model
     * @param array  $conditions
     * @param bool   $allowNone  When no match is found, return null instead of throwing an Exception.
     * @param array  $options
     *
     * @return object|null instance
     */
    public function one($model, $conditions, $allowNone = false, $options = [])
    {
        $collection = $this->all($model, $conditions, $options);
        $first = true;
        foreach ($collection as $item) {
            if ($first) {
                $instance = $item;
                $first = false;
            } else {
                throw new InfoException('More than 1 "'.$model.'" model matches the conditions', $conditions);
            }
        }
        if ($first) {
            if ($allowNone) {
                return;
            }
            throw new InfoException('No "'.$model.'" model found that matches the conditions', $conditions);
        }

        return $instance;
    }

    /**
     * Export an instance (or instance collection) as an array for use before serializing.
     *
     * Should be used in jsonSerialize when the instance is a JsonSerializable  (PHP 5.4)
     *
     * @link http://php.net/manual/en/class.jsonserializable.php
     *
     * @param string            $model
     * @param object|Collection $instance 1 instance or a collection of instances
     * @param int               $depth
     * @param array (internal) array with instances that should be exported (Detects infinite recursion)
     *
     * @return array
     */
    public function export($model, $instances, $depth = 0, $skip = [])
    {
        $export = [];
        if (is_array($instances) || $instances instanceof HasManyPlaceholder || $instances instanceof Collection) {
            foreach ($instances as $index => $instance) {
                $export[$index] = $this->export($model, $instance, $depth, $skip);
            }

            return $export;
        }
        $instance = $instances; // $instances is a single instance
        if (in_array($instance, $skip, true)) {
            throw new Exception('Recursion leak'); // Sanity check
        }
        $config = $this->_getConfig($model);
        $skip[] = $instance;
        $relations = array_merge(array_keys($config->belongsTo), array_keys($config->hasMany));
        foreach ($instance as $property => $value) {
            if (in_array($property, $relations) === false) { // Not a relation?
                $export[$property] = $value;
            } elseif ($depth !== 0) {
                if (isset($config->belongsTo[$property])) {
                    if (in_array($instance->$property, $skip, true)) { // Already exported
                        continue; // skip property
                    }
                    $export[$property] = $this->export($config->belongsTo[$property]['model'], $instance->$property, $depth - 1, $skip);
                } elseif ($depth !== 1) {
                    $export[$property] = [];
                    foreach ($instance->$property as $index => $item) {
                        if ($item instanceof Junction) {
                            $junctionInstance = $this->resolveInstance($item, $this->_getConfig($config->hasMany[$property]['model']));
                            if (in_array($junctionInstance, $skip, true)) {
                                unset($export[$property]); // skip property
                                break;
                            }
                            $export[$property][$index] = $this->export($config->hasMany[$property]['model'], $junctionInstance, $depth - 2, $skip);
                            PropertyPath::map($item, $export[$property][$index], array_flip($config->hasMany[$property]['fields']));
                        } else {
                            if (in_array($item, $skip, true)) {
                                unset($export[$property]); // skip property
                                break;
                            }
                            $export[$property][$index] = $this->export($config->hasMany[$property]['model'], $item, $depth - 2, $skip);
                        }
                    }
                }
            }
        }

        return $export;
    }

    /**
     * Create a instance from existing $data.
     * This won't store the data. For storing data use $repository->save($instance).
     *
     * @param string       $model
     * @param array/object $data    Raw data from the backend
     * @param array        $options
     *                              'preload' => int  The preload recursion level.
     *                              false or 0: Only the the relation.
     *                              1: Also the relations of the relation.
     *                              2: Also the relations of the relations of the relation.
     *                              N: Etc.
     *                              true or -1: Load all relations of all relations.
     *
     * @return instance
     */
    public function convert($model, $data, $options = [])
    {
        if (isset($options['junction'])) { // Should the instance be wrapped in a Junction?
            $junction = $options['junction'];
            unset($options['junction']);
            $instance = $this->convert($model, $data, $options);
            $key = PropertyPath::get($junction['reference'], $instance);
            $data = @$this->objects[$junction['model']][$junction['index']]['junctions'][$junction['property']][$key]; // $this->objects[$model][$index]['junctions'][$property][$key]
            if ($data === null) {
                throw new InfoException('No fields found for this junction', $junction);
            }
            $fields = [];
            PropertyPath::map($data, $fields, array_flip($junction['fields']));
            $junctionClass = $junction['class'];

            return new $junctionClass($instance, $fields, true);
        }
        if ($data === null) {
            throw new Exception('Parameter $data is required');
        }
        $config = $this->_getConfig($model);
        $index = $this->resolveIndex($data, $config);

        $object = @$this->objects[$model][$index];
        if ($object !== null) {
            // @todo validate $data against $object['data']
            $instance = $object['instance'];
        } else {
            $this->objects[$model][$index] = [
                'state' => 'retrieved',
                'instance' => null,
                'data' => $data,
            ];
            $instance = $this->convertToInstance($data, $config, $index);
            $this->objects[$model][$index]['instance'] = $instance;
        }
        if (isset($options['preload']) && $options['preload'] != 0) {
            $options['model'] = $model;
            --$options['preload']; // (this unexpectedly aso works for true, because true-- remains true)
            $this->resolveProperties($instance, $options);
        }

        return $instance;
    }

    /**
     * Retrieve all instances for the specified model.
     *
     * @param string $model
     * @param mixed  $conditions
     * @param array  $options
     *
     * @return Collection
     */
    public function all($model, $conditions = null, $options = [])
    {
        $config = $this->_getConfig($model);
        $collection = $this->_getBackend($config->backend)->all($config->backendConfig);
        $options['mapping'] = $this->collectionMappings[$model];
        $options['readFilters'] = $config->readFilters;
        $options['writeFilters'] = $config->writeFilters;
        $repoCollection = new RepositoryCollection($collection, $model, $this->ref(), $options);
        if ($conditions !== null) {
            return $repoCollection->where($conditions);
        }

        return $repoCollection;
    }

    /**
     * Retrieve a related instance (belongsTo) or collection (hasMany) and update the $instance.
     *
     * @param object $instance The instance with the relation.
     * @param string $property The relation property.
     * @param array  $options
     *                         'preload' => int  The preload recursion level.
     *                         false or 0: Only the the relation.
     *                         1: Also the relations of the relation.
     *                         2: Also the relations of the relations of the relation.
     *                         N: Etc.
     *                         true or -1: Load all relations of all relations.
     *                         'reload' => bool // Override the current value with the connection defined in the backend.
     *                         'model' => string // The model Improve speed and reliability of the lookup
     *
     * @return mixed related instance or null
     */
    public function resolveProperty($instance, $property, $options = [])
    {
        if (array_value($options, 'reload') == false && !($instance->$property instanceof BelongsToPlaceholder || $instance->$property instanceof HasManyPlaceholder)) { // Already resolved?
            return $instance->$property;
        }
        $model = array_value($options, 'model') ?: $this->resolveModel($instance); // Use te "model" options or detect based on the instance
        $config = $this->_getConfig($model);
        $index = $this->resolveIndex($instance, $config);

        $object = @$this->objects[$model][$index];
        if ($object === null || ($instance !== $object['instance'])) {
            throw new Exception('Instance not bound to this repository');
        }
        $belongsTo = array_value($config->belongsTo, $property);
        if ($belongsTo !== null) {
            if ($object['data'] === null) { // Instance is just created?
                $referencedId = $belongsTo['default'];
            } else {
                $referencedId = $object['data'][$belongsTo['reference']];
            }
            if ($referencedId === null) {
                throw new Exception('Unexpected id value: null'); // set property to null? or leave it alone?
            }
            if ($belongsTo['useIndex']) {
                return $instance->$property = $this->get($belongsTo['model'], $referencedId, $options);
            }
            $instances = $this->all($belongsTo['model'])->where([$belongsTo['id'] => $referencedId]);
            if (count($instances) != 1) {
                throw new InfoException('Multiple instances found for key "'.$referencedId.'" for belongsTo '.$model.'->belongsTo['.$property.'] references to non-id field: "'.$belongsTo['id'].'"');
            }

            return $instance->$property = $instances[0];
        }
        $hasMany = array_value($config->hasMany, $property);
        if ($hasMany !== null) {
            if (isset($this->objects[$model][$index]['hadMany'][$property])) {
                return $instance->$property = new Collection($this->objects[$model][$index]['hadMany'][$property]);
            }
            if (count($config->id) != 1) {
                throw new Exception('Complex keys not (yet) supported for hasMany relations');
            }
            $id = PropertyPath::get($config->properties[$config->id[0]], $instance);
            $hasManyConfig = $this->_getConfig($hasMany['model']);
            $hasManyBackend = $this->_getBackend($hasManyConfig->backend);
            $idProperty = $hasManyConfig->properties[$hasManyConfig->id[0]];
            if (isset($hasMany['through']) === false) {
                // one-to-many relation?
                $related = $hasManyBackend->related($hasManyConfig->backendConfig, $hasMany['reference'], $id);
            } else {
                // many-to-many relation.
                $junction = $this->junctions[$hasMany['through']];
                $junctionData = $this->_getBackend($junction->backend)->related($junction->backendConfig, $hasMany['reference'], $id);
                $junctions = $junctionData->selectKey($hasMany['id'])->toArray();

                $this->objects[$model][$index]['junctions'][$property] = $junctions;
                $ids = array_keys($junctions);

                if (count($hasMany['fields']) > 0) {
                    $options['junction'] = [
                        'model' => $model,
                        'index' => $index,
                        'property' => $property,
                        'reference' => $idProperty,
                        'fields' => $hasMany['fields'],
                        'class' => $hasMany['junctionClass'],
                    ];
                }
                if (count($ids) == 0) {
                    $related = new Collection([]);
                } else {
                    $related = $hasManyBackend->all($hasManyConfig->backendConfig)->where([$hasManyConfig->id[0].' IN' => $ids]);
                }
            }

            $options['mapping'] = $this->collectionMappings[$hasMany['model']];
            $collection = new RepositoryCollection($related, $hasMany['model'], $this->ref(), $options);
            if (isset($hasMany['conditions'])) {
                $collection = $collection->where($hasMany['conditions']);
            }

            $this->objects[$model][$index]['hadMany'][$property] = $collection->toArray(); // Add a items for change detection
            return $instance->$property = $collection;
        }
        throw new Exception('No association found for  '.$model.'->'.$property);
    }

    /**
     * Retrieve all related instances (belongTo) and collection (hasMany) and update the $instance.
     *
     * @param object $instance The instance with the relations.
     * @param array  $options
     *                         'preload' => int  The recursion level.
     *                         0: Only the the relation.
     *                         1: Also the relations of the relation.
     *                         2: Also the relations of the relations of the relation.
     *                         N: etc.
     *                         -1: Load all relations of all relations.
     *                         'model' => string // The model Improve speed and reliability of the lookup
     */
    public function resolveProperties($instance, $options = [])
    {
        if (in_array($instance, $this->loading, true)) {
            return;
        }
        $first = (count($this->loading) === 0);
        $this->loading[] = $instance;
        $model = array_value($options, 'model') ?: $this->resolveModel($instance); // Use te "model" options or detect based on the instance
        $options['model'] = $model;
        $config = $this->_getConfig($model);
        foreach (array_keys($config->belongsTo) as $property) {
            if ($instance->$property instanceof BelongsToPlaceholder) {
                $this->resolveProperty($instance, $property, $options);
            }
        }
        foreach (array_keys($config->hasMany) as $property) {
            if ($instance->$property instanceof HasManyPlaceholder) {
                $this->resolveProperty($instance, $property, $options);
            }
        }
        if ($first) {
            $this->loading = [];
        }
    }

    /**
     * Delete an instance.
     *
     * @param string      $model
     * @param instance|id $mixed The instance or id
     */
    public function delete($model, $mixed)
    {
        $config = $this->_getConfig($model);
        if (isset($this->deleted[$model])) {
            $isDeleted = false;
            if (is_object($mixed)) {
                $isDeleted = \Sledgehammer\collection($this->deleted[$model])->find($mixed, true);
            } else { // $mixed is an id
                $index = $this->resolveIndex($mixed, $config);
                $isDeleted = isset($this->deleted[$model][$index]);
            }
            if ($isDeleted) {
                // notice('Already deleted');
                return;
            }
        }
        $index = $this->resolveIndex($mixed, $config);
        $object = @$this->objects[$model][$index];
        if ($object === null) {
            if (is_object($mixed)) {
                throw new Exception('The instance is not bound to this Repository');
            }
            // The parameter is the id
            if (is_array($mixed)) {
                $data = $mixed;
            } else {
                $data = [$config->id[0] => $mixed]; // convert the id to array-notation
            }
        } elseif ($object['state'] == 'new') { // The instance isn't stored in the backend and only exists in-memory?
            throw new Exception('Removing instance failed, the instance isn\'t stored in the backend');
        } else {
            $data = $object['data'];
        }
        $instance = false;
        if (isset($object['instance'])) {
            $instance = $object['instance'];
        }
        $this->objects[$model][$index]['state'] = 'deleting';
        $this->_triggerEvent($instance, 'deleting', $instance, $this);
        $this->_getBackend($config->backend)->delete($data, $config->backendConfig);
        if ($instance) {
            $this->_triggerEvent($instance, 'deleted', $instance, $this);
            // Remove all public properties (making the object unusable)
            $instance = $object['instance'];
            $properties = array_keys(get_object_vars($instance));
            foreach ($properties as $property) {
                unset($instance->$property);
            }
        }
        // Remove the instances from connected collections
        foreach ($this->configs as $connectedConfig) {
            foreach ($connectedConfig->hasMany as $hasManyPath => $hasManyConfig) {
                if ($hasManyConfig['model'] === $model && isset($this->objects[$connectedConfig->name])) {
                    foreach ($this->objects[$connectedConfig->name] as $connectedObject) {
                        $collection = PropertyPath::get($hasManyPath, $connectedObject['instance']);
                        if ($collection instanceof Collection) {
                            $collection->remove($instance, true); // Remove the item from the collection (if it's there)
                        }
                        // @todo  Remove the reference from $connectedObject['hadMany'][$hasManyPath]? Could cause issues with many2many relations?
                    }
                }
            }
            // @todo Set the belongsTo to null?
        }
        $this->deleted[$model][$index] = $instance; // Register as deleted
        unset($this->objects[$model][$index]); // Remove the object from the repository
    }

    /**
     * Reload an instance from the connected backend.
     * Discards any unsaved changes.
     *
     * @param string      $model
     * @param instance|id $mixed   (optional) The instance or id
     * @param array       $options array(
     *                             'all' => (optional) bool reload all instances from this model (default: false)
     *                             'discard_changes' => (optional) Reload the instance, even when it has pending changes.
     *                             )
     */
    public function reload($model, $mixed = null, $options = [])
    {
        $config = $this->_getConfig($model);
        if (array_value($options, 'all')) {
            if ($mixed !== null) {
                throw new Exception('Can\'t use options[all] in combination with an id/instance');
            }
            unset($options['all']);
            foreach ($this->objects[$model] as $object) {
                $this->reload($model, $object['instance'], $options);
            }

            return;
        }

        $index = $this->resolveIndex($mixed, $config);
        $object = @$this->objects[$model][$index];
        if ($object === null) {
            if (is_object($mixed)) {
                throw new Exception('The instance is not bound to this Repository');
            }
        } elseif ($object['state'] == 'new') { // The instance isn't stored in the backend and only exists in-memory?
            throw new Exception('Reloading instance failed, the instance isn\'t stored in the backend');
        }
        if (is_object($mixed)) {
            $id = [];
            foreach ($config->id as $key) {
                $id[$key] = $object['data'][$key];
            }
        } elseif (is_array($mixed)) {
            $id = $mixed;
        } else {
            $id = [$config->id[0] => $mixed];
        }
        if (array_value($options, 'discard_changes') !== true) {
            // Check changes
            $data = $this->convertToData($this->objects[$model][$index]['instance'], $config);
            if ($data !== $this->objects[$model][$index]['data']) {
                throw new InfoException('Reloading failed, instance has pending changes', [
                    'changed in instance' => array_diff_assoc($data, $this->objects[$model][$index]['data']),
                    'backend values' => array_diff_assoc($this->objects[$model][$index]['data'], $data),
                ]);
            }
        }
        $data = $this->_getBackend($config->backend)->get($id, $config->backendConfig);
        $this->objects[$model][$index]['data'] = $data;
        $this->objects[$model][$index]['state'] = 'retrieved';

        return $this->convertToInstance($data, $config, $index, true);
    }

    /**
     * Create an in-memory instance of the model, ready to be saved.
     *
     * @param string $model
     * @param array  $values Initial contents of the object (optional)
     *
     * @return object
     */
    public function create($model, $values = [])
    {
        $config = $this->_getConfig($model);
        $values = array_merge($config->defaults, $values);
        $index = uniqid('TMP-');
        $class = $config->class;
        $instance = new $class();
        // Apply initial values
        foreach ($values as $path => $value) {
            PropertyPath::set($path, $value, $instance);
        }
        foreach ($config->belongsTo as $path => $relation) {
            if (array_key_exists($path, $values)) { // A value is provided in the $values array?
                continue;
            }
            if (isset($relation['default'])) {
                $belongsToIndex = $this->resolveIndex($relation['default']);
                $value = @$this->objects[$relation['model']][$belongsToIndex]['instance'];
                if ($value === null) {
                    $value = new BelongsToPlaceholder($this->ref().'/'.$config->name.'/'.$path, $instance, [$relation['id'] => $relation['default']]);
                }
                PropertyPath::set($path, $value, $instance);
            } else {
                PropertyPath::set($path, null, $instance);
            }
        }
        foreach (array_keys($config->hasMany) as $path) {
            $value = PropertyPath::get($path, $instance);
            if (is_array($value)) {
                PropertyPath::set($path, new Collection($value), $instance);
            }
        }
        $this->objects[$model][$index] = [
            'state' => 'new',
            'instance' => $instance,
            'data' => null,
        ];
        $this->created[$model][$index] = $instance;
        $this->_triggerEvent($instance, 'create', $instance, ['repository' => $this->ref(), 'model' => $config->name], $this);

        return $instance;
    }

    /**
     * Store the instance.
     *
     * @param string   $model
     * @param stdClass $instance
     * @param array    $options
     *                           'ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances,
     *                           'add_unknown_instance' => bool, false: Reject unknown instances. (use $repository->create())
     *                           'reject_unknown_related_instances' => bool, false: Auto adds unknown instances
     *                           'keep_missing_related_instances' => bool, false: Auto deletes removed instances
     *                           }
     */
    public function save($model, $instance, $options = [])
    {
        $relationSaveOptions = $options;
        $relationSaveOptions['add_unknown_instance'] = (value($options['reject_unknown_related_instances']) == false);
        $config = $this->_getConfig($model);
        if (is_object($instance) === false) {
            throw new Exception('Invalid parameter $instance, must be an object');
        }
        $index = $this->resolveIndex($instance, $config);

        $object = @$this->objects[$model][$index];
        if ($object === null) {
            foreach ($this->created[$config->name] as $createdIndex => $created) {
                if ($instance === $created) {
                    $index = $createdIndex;
                    $object = $this->objects[$model][$index];
                    break;
                }
            }
        }
        if ($object === null || $object['instance'] !== $instance) {
            if ($instance instanceof Junction) {
                throw new Exception('Can\'t save a Junction directly');
            }
            $resolvedModel = $this->resolveModel($instance);
            if ($model !== $resolvedModel) {
                throw new Exception('Can\'t save an "'.$resolvedModel.'" as an "'.$model.'"');
            }
            // id/index change-detection
            foreach ($this->objects[$model] as $object) {
                if ($object['instance'] === $instance) {
                    throw new Exception('Change rejected, the index changed from '.$this->resolveIndex($object['data'], $config).' to '.$index);
                }
            }
            throw new Exception('The instance is not bound to this Repository');
        }

        $rootSave = (count($this->saving) === 0);
        if ($rootSave === false) {
            if (in_array($instance, $this->saving, true)) { // Recursion loop detected?
                return; // Prevent duplicate saves.
            }
        }
        $this->saving[] = $instance;

        $previousState = $object['state'];
        try {
            if ($object['state'] == 'saving') {
                throw new Exception('Object already in the saving state');
            }
            $this->objects[$model][$index]['state'] = 'saving';
            $this->_triggerEvent($instance, 'saving', $instance, $this);

            // Save belongsTo
            if (value($options['ignore_relations']) == false) {
                foreach ($config->belongsTo as $property => $belongsTo) {
                    if ($instance->$property !== null && ($instance->$property instanceof BelongsToPlaceholder) == false) {
                        $this->save($belongsTo['model'], $instance->$property, $relationSaveOptions);
                    }
                }
            }

            // Save instance
            $data = $this->convertToData($object['instance'], $config);
            if ($previousState == 'new') {
                $object['data'] = $this->_getBackend($config->backend)->add($data, $config->backendConfig);
                unset($this->created[$config->name][$index]);
                unset($this->objects[$config->name][$index]);
                $changes = array_diff_assoc($object['data'], $data);
                if (count($changes) > 0) { // Has the data changed, for example by an auto-incremented id?
                    foreach ($changes as $column => $value) {
                        if (isset($config->readFilters[$column])) {
                            $value = \Sledgehammer\filter($value, $config->readFilters[$column]);
                        }
                        if (isset($config->properties[$column])) {
                            PropertyPath::set($config->properties[$column], $value, $instance);
                        }
                    }
                }
                $index = $this->resolveIndex($instance, $config);
                // @todo check if index already exists?
                $this->objects[$model][$index] = $object;
            } else {
                $this->objects[$model][$index]['data'] = $this->_getBackend($config->backend)->update($data, $object['data'], $config->backendConfig);
            }

            // Save hasMany
            if (value($options['ignore_relations']) == false) {
                foreach ($config->hasMany as $property => $hasMany) {
                    if ($instance->$property instanceof HasManyPlaceholder) {
                        continue; // No changes (It's not even accessed)
                    }
                    $collection = $instance->$property;
                    if ($collection instanceof Traversable) {
                        $collection = iterator_to_array($collection);
                    }
                    if ($collection === null) {
                        notice('Expecting an array for property "'.$property.'"');
                        $collection = [];
                    }
                    // Determine old situation
                    $old = @$this->objects[$model][$index]['hadMany'][$property];
                    if ($old === null && $previousState != 'new' && is_array($collection)) { // Is the property replaced, before the placeholder was replaced?
                        // Load the previous situation
                        $oldValue = $instance->$property;
                        $old = $this->resolveProperty($instance, $property, ['model' => $model, 'reload' => true])->toArray();
                        $instance->$property = $oldValue;
                    }
                    if (isset($hasMany['collection']['valueField'])) {
                        if (count(array_diff_assoc($old, $collection)) != 0) {
                            warning('Saving changes in complex hasMany relations are not (yet) supported.');
                        }
                        continue;
                    }
                    if (isset($hasMany['belongsTo'])) { // One to Many?
                        $belongsToProperty = $hasMany['belongsTo'];
                        foreach ($collection as $key => $item) {
                            // Connect the items to the instance
                            if (is_object($item)) {
                                $item->$belongsToProperty = $instance;
                                if ($item instanceof BelongsToPlaceholder) {
                                    $replacedItem = $this->resolveInstance($item, $this->_getConfig($hasMany['model']));
                                    if ($replacedItem->$belongsToProperty !== $instance) {
                                        throw new Exception('Invalid placeholder in "'.$model.'->'.$property.'"');
                                    }
                                    $collection[$key] = $replacedItem;
                                    $item = $replacedItem;
                                }
                                $this->save($hasMany['model'], $item, $relationSaveOptions);
                            } elseif ($item !== array_value($old, $key)) {
                                warning('Unable to save the change "'.$item.'" in '.$config->name.'->'.$property.'['.$key.']');
                            }
                        }
                    } elseif (isset($hasMany['through'])) { // Many to Many?
                        $hasManyConfig = $this->_getConfig($hasMany['model']);
                        // Save changes in the related items
                        foreach ($collection as $item) {
                            if ($item instanceof Junction) {
                                $item = $this->resolveInstance($item, $hasManyConfig);
                            }
                            $this->save($hasMany['model'], $item, $relationSaveOptions);
                        }
                        $junctionConfig = $this->junctions[$hasMany['through']];
                        $junctionBackend = $this->_getBackend($junctionConfig->backend);

                        $hasManyIdPath = $hasManyConfig->properties[$hasManyConfig->id[0]];

                        $oldJunctions = @$object['junctions'][$property];
                        if ($oldJunctions === null) {
                            if ($object['state'] === 'new') {
                                $oldJunctions = [];
                            } else {
                                $oldValue = $instance->$property;
                                $old = $this->resolveProperty($instance, $property, ['model' => $model, 'reload' => true])->toArray();
                                $instance->$property = $oldValue;
                                $object = $this->objects[$model][$index];
                                $oldJunctions = $object['junctions'][$property];
                                if ($oldJunctions === null) {
                                    throw new Exception('Failed to determine previous junctions');
                                }
                            }
                        }
                        $junctions = [];
                        $id = PropertyPath::get($config->properties[$config->id[0]], $instance);
                        foreach ($collection as $key => $item) {
                            $hasManyId = PropertyPath::get($hasManyIdPath, $item);
                            $oldJunction = @$oldJunctions[$hasManyId];
                            $junction = [
                                $hasMany['reference'] => $id,
                                $hasMany['id'] => $hasManyId,
                            ];
                            if ($item instanceof Junction) {
                                PropertyPath::map($item, $junction, $hasMany['fields']);
                            }
                            $junctions[$hasManyId] = $junction;
                            $junctionChanged = false;
                            if ($oldJunction === null) { // New relation?
                                $junctionBackend->add($junction, $junctionConfig->backendConfig);
                                $junctionChanged = true;
                            } else {
                                if (count(array_diff($junction, $oldJunction)) != 0) {
                                    $junctionBackend->update($junction, $oldJunction, $junctionConfig->backendConfig);
                                    $junctionChanged = true;
                                }
                            }
                            if ($junctionChanged) {
                                // Update the $instance in the $item->hasMany collection.
                                foreach ($hasManyConfig->hasMany as $manyToManyProperty => $manyToMany) {
                                    if (isset($manyToMany['through']) && $manyToMany['through'] === $hasMany['through']) {
                                        if ($item->$manyToManyProperty instanceof HasManyPlaceholder) {
                                            break; // collection not loaded.
                                        }

                                        $manyToManyIndex = $this->resolveIndex($item, $hasManyConfig);
                                        $this->objects[$hasMany['model']][$manyToManyIndex]['junctions'][$manyToManyProperty][$id] = $junction; // Prevent adding / updating the junction twice.
                                        $manyToManyExists = false;
                                        foreach ($item->$manyToManyProperty as $manyToManyKey => $manyToManyItem) {
                                            $manyToManyInstance = ($manyToManyItem instanceof Junction) ? $this->resolveInstance($manyToManyItem, $config) : $manyToManyItem;
                                            if ($instance === $manyToManyInstance) { // Instance already exists in the relation?
                                                $manyToManyExists = true;
                                                break;
                                            }
                                        }
                                        if (count($manyToMany['fields']) != 0) { // Update the Junction values
                                            if ($manyToManyExists && $manyToManyItem instanceof Junction) {
                                                PropertyPath::map($junction, $manyToManyItem, array_flip($manyToMany['fields']));
                                            } else {
                                                $fields = [];
                                                PropertyPath::map($junction, $fields, array_flip($manyToMany['fields']));
                                                $junctionClass = (isset($hasMany['junctionClass']) ? $hasMany['junctionClass'] : '\Sledgehammer\\Junction');
                                                $manyToManyItem = new $junctionClass($instance, $fields, true);
                                            }
                                        } else {
                                            $manyToManyItem = $instance;
                                        }
                                        if ($oldJunction === null) {
                                            if ($manyToManyExists === false) { // Instance not found in the relation?
                                                // @todo Wrap in a Junction?
                                                $item->{$manyToManyProperty}[] = $manyToManyItem; // add instance to the collection/array.
                                            }
                                            $this->objects[$hasMany['model']][$manyToManyIndex]['hadMany'][$manyToManyProperty][] = $manyToManyItem;
                                        }
                                    }
                                }
                            }
                        }
                        $this->objects[$model][$index]['junctions'][$property] = $junctions;
                    } else {
                        notice('Unable to verify/update foreign key'); // @TODO: implement raw fk injection.
                    }
                    if (value($options['keep_missing_related_instances']) == false) {
                        // Delete items that are no longer in the relation
                        if ($old !== null) {
                            if ($collection === null && count($old) > 0) {
                                notice('Unexpected value: null for property "'.$property.'", expecting an array or Iterator');
                            }
                            foreach ($old as $key => $item) {
                                if (in_array($item, $collection, true) === false) {
                                    if (!empty($hasMany['through']) && !empty($hasMany['fields'])) { // Can't compare Junctions using a identity check
                                        $getJunctionId = PropertyPath::compile($hasManyIdPath);
                                        $oldId = $getJunctionId($item);
                                        foreach ($collection as $newItem) {
                                            $newId = $getJunctionId($newItem);
                                            if ($newId === $oldId) {
                                                continue 2;
                                            }
                                        }
                                    }
                                    if (is_object($item)) {
                                        if (empty($hasMany['through'])) { // one-to-many?
                                            $this->delete($hasMany['model'], $item); // Delete the related model
                                        } else {
                                            // Delete the junction (many-to-many)
                                            $data = [
                                                $hasMany['reference'] => PropertyPath::get($config->properties[$config->id[0]], $instance),
                                                $hasMany['id'] => PropertyPath::get($hasManyIdPath, $item),
                                            ];
                                            $junctionConfig = $this->junctions[$hasMany['through']];
                                            $junctionBackend = $this->_getBackend($junctionConfig->backend);
                                            $junctionBackend->delete($data, $junctionConfig->backendConfig);

                                            // Also remove the $instance from the $item->hasMany collection.
                                            foreach ($hasManyConfig->hasMany as $manyToManyProperty => $manyToMany) {
                                                if (isset($manyToMany['through']) && $manyToMany['through'] === $hasMany['through']) {
                                                    if ($item->$manyToManyProperty instanceof HasManyPlaceholder) {
                                                        break; // collection not loaded.
                                                    }
                                                    foreach ($item->$manyToManyProperty as $manyToManyKey => $manyToManyItem) {
                                                        $manyToManyInstance = ($manyToManyItem instanceof Junction) ? $this->resolveInstance($manyToManyItem, $config) : $manyToManyItem;
                                                        if ($manyToManyInstance === $instance) { // Instance found in the relation?
                                                            unset($item->{$manyToManyProperty}[$manyToManyKey]);
                                                            break;
                                                        }
                                                    }
                                                    $manyToManyIndex = $this->resolveIndex($item, $hasManyConfig);
                                                    foreach ($this->objects[$hasMany['model']][$manyToManyIndex]['hadMany'][$manyToManyProperty] as $manyToManyKey => $manyToManyItem) {
                                                        $manyToManyInstance = ($manyToManyItem instanceof Junction) ? $this->resolveInstance($manyToManyItem, $config) : $manyToManyItem;
                                                        if ($manyToManyInstance === $instance) { // Instance found in the relation?
                                                            // Update backend data, so re-adding the connection will be detected.
                                                            unset($this->objects[$hasMany['model']][$manyToManyIndex]['hadMany'][$manyToManyProperty][$manyToManyKey]);
                                                            unset($this->objects[$hasMany['model']][$manyToManyIndex]['junctions'][$manyToManyProperty][$data[$hasMany['reference']]]);
                                                            break;
                                                        }
                                                    }
                                                    break;
                                                }
                                            }
                                        }
                                    } else {
                                        warning('Unable to remove item['.$key.']: "'.$item.'" from '.$config->name.'->'.$property);
                                    }
                                }
                            }
                        }
                    }
                    $this->objects[$model][$index]['hadMany'][$property] = $collection;
                }
            }
            $this->objects[$model][$index]['state'] = 'saved';
            $this->_triggerEvent($instance, 'saved', $instance, $this);
        } catch (Exception $e) {
            if ($rootSave) {
                $this->saving = []; // reset saving array.
            }
            $this->objects[$model][$index]['state'] = $previousState; // @todo Or is an error state more appropriate?
            throw $e;
        }
        if ($rootSave) {
            $saved = count($this->saving);
            $this->saving = []; // reset saving array.
            return $saved;
        }
    }

    /**
     * Search for model classnames in the given $namespace.
     *
     * @param string $namespace
     */
    public function registerNamespace($namespace)
    {
        if (substr($namespace, -1) !== '\\') {
            $namespace .= '\\';
        }
        array_unshift($this->namespaces, $namespace);
    }

    /**
     * Register all models from the backend.
     * Aslo validates and corrects the model configurations.
     *
     * @param RepositoryBackend $backend
     */
    public function registerBackend($backend)
    {
        if ($backend->identifier === null) {
            throw new Exception('RepositoryBackend->identifier is required');
        }
        if (count($backend->configs) === 0) {
            notice(get_class($backend).': "'.$backend->identifier.'" doesn\'t have any ModelConfigs');

            return;
        }
        if (isset($this->backends[$backend->identifier])) {
            throw new Exception('RepositoryBackend "'.$backend->identifier.'" already registered');
        }
        $this->backends[$backend->identifier] = $backend;
        // Pass 1: Register configs
        foreach ($backend->configs as $config) {
            if ($config->backend === null) {
                $config->backend = $backend->identifier;
            }
            $this->registerModel($config);
        }
        // Pass 2: Register junctions
        foreach ($backend->junctions as $junction) {
            if ($junction->backend === null) {
                $junction->backend = $backend->identifier;
            }
            if (isset($this->junctions[$junction->name])) {
                notice('overwriting junction '.$junction->name);
            }
            $this->junctions[$junction->name] = $junction;
        }
        // Pass 3: Auto detect id's
        foreach ($backend->configs as $backendConfig) {
            $config = $this->configs[$backendConfig->name];
            if (count($config->id) === 0) {
                if (isset($config->properties['id'])) { // No id set, but the column 'id' exists?
                    $config->id = ['id'];
                } else {
                    warning('Invalid config: '.$config->name.'->id is not configured and could not detect an "id" element');
                }
            }
        }
        // Pass 4: Validate and correct configs
        foreach ($backend->configs as $backendConfig) {
            $config = $this->configs[$backendConfig->name];
            if (count($config->properties) === 0) {
                warning('Invalid config: '.$config->name.'->properties array is not configured');
            }
            foreach ($config->id as $idIndex => $idColumn) {
                if (isset($config->properties[$idColumn])) {
                    $idProperty = $config->properties[$idColumn];
                } else {
                    warning('Invalid config: '.$config->name.'->id['.$idIndex.']: "'.$idColumn.'" isn\'t mapped as a property');
                }
            }
            foreach ($config->belongsTo as $property => $belongsTo) {
                $validationError = false;
                if (is_array($belongsTo) === false) {
                    $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'] should be an array';
                }
                if (empty($belongsTo['model'])) {
                    $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'][model] not set';
                } elseif (empty($belongsTo['reference']) && empty($belongsTo['convert'])) {
                    $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'] is missing a [reference] or [convert] element';
                } elseif (isset($belongsTo['convert']) && isset($belongsTo['reference'])) {
                    $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'] can\'t contain both a [reference] and a [convert] element';
                }
                if (isset($belongsTo['reference'])) {
                    if (empty($belongsTo['id'])) { // id not set, but (target)model is configured?
                        if (empty($this->configs[$belongsTo['model']])) {
                            $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'][id] couldn\'t be inferred, because model "'.$belongsTo['model'].'" isn\'t registered';
                        } else {
                            $belongsToConfig = $this->_getConfig($belongsTo['model']);
                            // Infer/Assume that the id is the ID from the model
                            if (count($belongsToConfig->id) == 1) {
                                $belongsTo['id'] = current($belongsToConfig->id);
                                $config->belongsTo[$property]['id'] = $belongsTo['id']; // Update config
                            } else {
                                $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'][id] not set and can\'t be inferred (for a complex key)';
                            }
                        }
                    }
                    if (isset($belongsTo['reference']) && isset($belongsTo['useIndex']) == false) {
                        if (empty($this->configs[$belongsTo['model']])) {
                            $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'][useIndex] couldn\'t be inferred, because model "'.$belongsTo['model'].'" isn\'t registered';
                        } else {
                            $belongsToConfig = $this->_getConfig($belongsTo['model']);
                            // Is the foreign key is linked to the model id
                            $belongsTo['useIndex'] = (count($belongsToConfig->id) == 1 && $belongsTo['id'] == current($belongsToConfig->id));
                            $config->belongsTo[$property]['useIndex'] = $belongsTo['useIndex']; // Update config
                        }
                    }
                    if (isset($belongsTo['id'])) {
                        // Add foreign key to the collection mapping
                        $this->collectionMappings[$config->name][$property.'->'.$belongsToConfig->properties[$belongsTo['id']]] = $belongsTo['reference'];
                        $this->collectionMappings[$config->name][$property.'.'.$belongsToConfig->properties[$belongsTo['id']]] = $belongsTo['reference'];
                    }
                }
                // @todo Add collectionMapping for "convert" relations?
                if (empty($this->configs[$belongsTo['model']])) {
                    // $validationError = 'Invalid config: '.$config->name.'->belongsTo['.$property.'][model] "'.$belongsTo['model'].'" isn\'t registered';
                }

                // Remove invalid relations
                if ($validationError) {
                    warning($validationError);
                    unset($config->belongsTo[$property]);
                }
            }
            foreach ($config->hasMany as $property => $hasMany) {
                $validationError = false;
                if (empty($hasMany['model'])) {
                    $validationError = 'Invalid config: '.$config->name.'->hasMany['.$property.'][model] not set';
                } elseif (isset($hasMany['convert'])) {
                    // no additional fields are needed.
                } elseif (empty($hasMany['reference'])) {
                    // @todo Infer property (lookup belongsTo)
                    $validationError = 'Invalid hasMany: '.$config->name.'->hasMany['.$property.'][reference] not set';
                } elseif (isset($hasMany['reference']) && empty($hasMany['belongsTo'])) {
                    $referencePath = PropertyPath::parse($hasMany['reference']);
                    if (count($referencePath) == 1) {
                        // The foreign key is linked directly
                    } elseif (empty($this->configs[$hasMany['model']])) {
                        $validationError = 'Invalid config: '.$config->name.'->hasMany['.$property.'][belongsTo] couldn\'t be inferred, because model "'.$hasMany['model'].'" isn\'t registered';
                    } else {
                        // Infer the belongsTo path based on the model and reference path.
                        $hasManyConfig = $this->configs[$hasMany['model']];
                        $idProperty = [
                            array_value(array_pop($referencePath), 1),
                        ];
                        if ($idProperty == $hasManyConfig->id) {
                            $hasMany['belongsTo'] = PropertyPath::assemble($referencePath);
                            $config->hasMany[$property]['belongsTo'] = $hasMany['belongsTo']; // update config
                        }
                    }
                }
                if (isset($hasMany['through'])) {
                    if (array_key_exists('fields', $hasMany) === false) {
                        $config->hasMany[$property]['fields'] = [];
                    } elseif (array_key_exists('junctionClass', $hasMany) === false) {
                        $config->hasMany[$property]['junctionClass'] = Junction::class;
                    }
                    $junctionConfig = @$this->junctions[$hasMany['through']];
                    if (array_key_exists('idPath', $hasMany) === false && $junctionConfig) {
//                        dump($junctionConfig);
                    }
                }
                // Remove invalid relations
                if ($validationError) {
                    warning($validationError);
                    unset($config->hasMany[$property]);
                }
            }
            // Validate read & write filters
            foreach ($config->readFilters as $column => $filter) {
                if (empty($config->properties[$column])) {
                    notice('Invalid config: '.$config->name.'->readFilters['.$column.'] isn\'t mapped as property', $filter);
                }
            }
            foreach ($config->writeFilters as $column => $filter) {
                if (empty($config->properties[$column])) {
                    notice('Invalid config: '.$config->name.'->writeFilters['.$column.'] isn\'t mapped as property', $filter);
                }
            }
        }
        
        // Pass 5: Generate classes based on properties when no class is detected/found.
        foreach ($backend->configs as $config) {
            if (substr($config->class, 0, 11) !== '\\Generated\\') {
                $this->validated[$config->name] = false;
            } else {
                $this->validated[$config->name] = true; // Generated classes are valid by design.
                if (class_exists($config->class, false)) {
                    notice('Skipped generating class: "'.$config->class.'", a class with the same name exists');
                    continue;
                }
                $parts = explode('\\', $config->class);
                array_pop($parts); // remove class part.
                $namespace = implode('\\', array_slice($parts, 1));

                // Generate class
                $use = '';
                $aliases = [];
                $alias = $this->buildAlias(Base::class, $aliases, $use);
                $php = "\nclass ".$config->name." extends ".$alias."\n{\n";
                $properties = [];
                foreach ($config->properties as $path) {
                    $parsedPath = PropertyPath::parse($path);
                    $property = $parsedPath[0][1];
                    if (!in_array($property, $properties)) {
                        $php .= "    public $".$property.";\n";
                        $properties[] = $property;
                    }
                }
                foreach ($config->belongsTo as $path => $belongsTo) {
                    $parsedPath = PropertyPath::parse($path);
                    $belongsToConfig = $this->_getConfig($belongsTo['model']);
                    $alias = $this->buildAlias($belongsToConfig->class, $aliases, $use);
                    $property = $parsedPath[0][1];
                    $php .= "\n";
                    $php .= "    /**\n";
                    $php .= "     * @var ".$alias.' The associated '.$belongsToConfig->name."\n";
                    $php .= "     */\n";
                    $php .= "    public $".$property.";\n";
                }
                foreach ($config->hasMany as $path => $hasMany) {
                    $parsedPath = PropertyPath::parse($path);
                    $hasManyConfig = $this->_getConfig($hasMany['model']);
                    $property = $parsedPath[0][1];
                    $aliasC = $this->buildAlias(Collection::class, $aliases, $use);
                    $alias = $this->buildAlias($hasManyConfig->class, $aliases, $use);
                    $php .= "\n";
                    $php .= "    /**\n";
                    $php .= "     * @var ".$aliasC."|".$alias."[] A collection with the associated ".$hasManyConfig->plural."\n";
                    $php .= "     */\n";
                    $php .= "    public $".$property.";\n";
                }
                $php .= '}';
                $php = "namespace ".$namespace.";\n\n".$use.$php;
                if (self::$autoCompleteFolder && $namespace === 'Generated') {
                    // Write autoComplete helper
                    // @todo Only write file when needed, aka validate $this->autoComplete
                    file_put_contents(self::$autoCompleteFolder.$config->name.'.php', "<?php\n\n".$php."\n");
                }
                eval($php);
            }
        }
        // Pass 6: Generate or update the AutoComplete Helper for the default repository?
        if (self::$autoCompleteFolder && isset(self::$instances['default']) && self::$instances['default'] === $this) {
            $autoCompleteFile = self::$autoCompleteFolder.'repository.ini';
            if ($this->autoComplete === null) {
                if (file_exists($autoCompleteFile)) {
                    $this->autoComplete = parse_ini_file($autoCompleteFile, true);
                } else {
                    $this->autoComplete = [];
                }
            }
            // Validate AutoCompleteHelper
            $outdated = false;
            foreach ($backend->configs as $config) {
                $autoComplete = [
                    'class' => $config->class,
                    'properties' => implode(', ', $config->properties),
                ];
                if (empty($this->autoComplete[$config->name]) || $this->autoComplete[$config->name] != $autoComplete) {
                    $this->autoComplete[$config->name] = $autoComplete;
                    $outdated = true;
                }
            }
            if ($outdated) {
                \Sledgehammer\write_ini_file($autoCompleteFile, $this->autoComplete, 'Repository AutoComplete config');
                $this->writeAutoCompleteHelper(self::$autoCompleteFolder.'DefaultRepository.php');
            }
        }
    }

    /**
     * @param ModelBehavior $behavior
     * @param string        $model
     */
    public function registerBehavior($behavior, $model)
    {
        $config = $this->_getConfig($model);
        if ($behavior->identifier === null) {
            $behavior->identifier = uniqid(basename(get_class($behavior)));
        }
        if (empty($this->backends[$behavior->identifier])) {
            $this->backends[$behavior->identifier] = $behavior;
            $behavior->backend = $this->_getBackend($config->backend);
        } elseif ($behavior->backend !== $this->_getBackend($config->backend)) {
            throw new Exception('Can\'t reuse the same ModelBehavior for multiple backends');
        }
        $config->backend = $behavior->identifier;
        $behavior->register($config);
    }
    
    /**
     * Register closures that lazily configure the default repository.
     *
     * @param Closure $closure A closure that received the repository as the first argument
     */
    public static function configureDefault($closure)
    {
        if (\Sledgehammer\is_closure($closure) === false) {
            throw new Exception('Closure expected as first argument');
        }
        if (static::$lazyConfigurations === false) { // default repository already initialized
            $closure(Repository::instance());
        } else {
            static::$lazyConfigurations[] = $closure;
        }
    }
    
    protected static function defaultInstance()
    {
        $repo = new Repository();
        Repository::$instances['default'] = $repo;
        foreach (static::$lazyConfigurations as $configure) {
            $configure($repo);
        }
        static::$lazyConfigurations = false;
        return $repo;
    }
    /**
     * Builds a uses array
     *
     * @param string $fqcn full qualified classname
     * @param array $aliases array with the
     * @return string alias
     */
    private function buildAlias($fqcn, &$aliases, &$use)
    {
        $fqcn = ltrim($fqcn, '\\');
        if (array_key_exists($fqcn, $aliases)) {
            return $aliases[$fqcn];// Use the existing alias
        }
        $parts = explode('\\', $fqcn);
        $alias = array_pop($parts);
        if (in_array($alias, $aliases)) { // naming collision?
            $alias = uniqid($alias);
        }
        $aliases[$fqcn] = $alias;
        // rebuild use statements, alphabetically
        $use = '';
        foreach ($aliases as $_fqcn => $_alias) {
            $parts = explode('\\', $_fqcn);
            if ($_alias === array_pop($parts)) {
                $use .= "use ".$_fqcn.";\n";
            } else {
                $use .= "use ".$_fqcn." as ".$_alias.";\n";
            }
        }
        return $alias;
    }

    /**
     * Check if a model is configured in this repository.
     *
     * @param string $model
     *
     * @return bool
     */
    public function isConfigured($model)
    {
        return isset($this->configs[$model]);
    }

    /**
     * Get the unsaved changes.
     *
     * @param string   $model
     * @param stdClass $instance
     *
     * @return array
     */
    public function diff($model, $instance)
    {
        $config = $this->_getConfig($model);
        $index = $this->resolveIndex($instance, $config);
        $new = $this->convertToData($instance, $config);
        $object = $this->objects[$model][$index];
        if ($object['state'] == 'new') {
            $old = $config->defaults;
        } else {
            $old = $object['data'];
        }
        $diff = array_diff_assoc($new, $old);
        $changes = [];
        foreach ($diff as $key => $value) {
            if ($object['state'] == 'new') {
                $changes[$key]['next'] = $value;
            } else {
                $changes[$key]['previous'] = $old[$key];
                $changes[$key]['next'] = $value;
            }
        }

        return $changes;
    }

    /**
     * Lookup a modelname for an instance bound to this repository.
     *
     * @throws Exceptions on failure
     *
     * @param stdClass $instance
     *
     * @return string model
     */
    public function resolveModel($instance)
    {
        if ($instance instanceof BelongsToPlaceholder) {
            throw new InfoException('Unable to determine model for BelongsToPlaceholder objects', $instance);
        }
        if ($instance instanceof Junction) {
            throw new Exception('Unable to determine model for Junction objects');
        }
        $class = get_class($instance);
        foreach ($this->configs as $model => $config) {
            if ($config->class === '\\'.$class) {
                foreach ($this->objects[$model] as $object) {
                    if ($object['instance'] === $instance) {
                        return $model;
                    }
                }
            }
        }
        throw new InfoException('Instance not bound to this repository"', $instance);
    }
    
    /**
     * Free object refercences from memory.
     * @param string $model
     * @param mixed $instance
     */
    public function free($model, $instance = null)
    {
        if ($instance === null) {
            unset($this->objects[$model]);
            return;
        }
        $config = $this->_getConfig($model);
        $id = $this->resolveIndex($instance, $config);
        unset($this->objects[$model][$id]);
    }

    /**
     * Generate php sourcecode of an subclass of Repository with all magic model functions written as normal functions.
     * Allows AutoCompletion of the magic get$Model(), save$Model(), etc functions.
     *
     * @param string $filename
     * @param string $class     The classname of the genereted class
     * @param string $namespace (optional) The namespace of the generated class
     */
    public function writeAutoCompleteHelper($filename, $class = 'DefaultRepository', $namespace = 'Generated')
    {
        $use = '';
        $aliases = [];
        $this->buildAlias(Collection::class, $aliases, $use);
        $php = "\nclass ".$class.' extends '.$this->buildAlias(get_class($this), $aliases, $use)."\n";
        $php .= "{\n";
        foreach ($this->configs as $model => $config) {
            $alias = $this->buildAlias($config->class, $aliases, $use);
            $instanceVar = '$'.lcfirst($model);
            $php .= "    /**\n";
            $php .= "     * Retrieve an ".$model."\n";
            $php .= "     *\n";
            $php .= "     * @param mixed \$id  The ".$model." ID\n";
            $php .= "     * @param array \$options\n";
            $php .= "     *  'preload' => int  The preload recursion level.\n";
            $php .= "     *     false or 0: Only the the relation.\n";
            $php .= "     *     1: Also the relations of the relation.\n";
            $php .= "     *     2: Also the relations of the relations of the relation.\n";
            $php .= "     *     N: Etc.\n";
            $php .= "     *    true or -1: Load all relations of all relations.\n";
            $php .= "     * @return ".$alias."\n";
            $php .= "     */\n";
            $php .= "    public function get".$model.'($id, $options = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->get('".$model."', \$id, \$options);\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Retrieve one ".$model." based on criteria\n";
            $php .= "     *\n";
            $php .= "     * @param mixed \$conditions\n";
            $php .= "     * @param bool \$allowNone  When no match is found, return null instead of throwing an Exception.\n";
            $php .= "     * @param array \$options\n";
            $php .= "     * @return ".$alias."\n";
            $php .= "     */\n";
            $php .= "    public function one".$model.'($conditions, $allowNone = false, $options = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->one('".$model."', \$conditions, \$allowNone, \$options);\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Retrieve all ".$config->plural."\n";
            $php .= "     *\n";
            $php .= "     * @param mixed \$conditions\n";
            $php .= "     * @param array \$options\n";
            $php .= "     *  'preload' => int  The preload recursion level.\n";
            $php .= "     *     false or 0: Only the the relation.\n";
            $php .= "     *     1: Also the relations of the relation.\n";
            $php .= "     *     2: Also the relations of the relations of the relation.\n";
            $php .= "     *     N: Etc.\n";
            $php .= "     *    true or -1: Load all relations of all relations.\n";
            $php .= "     *\n";
            $php .= "     * @return Collection|".$alias."[]\n";
            $php .= "     */\n";
            $php .= "    public function all".$config->plural.'($conditions = null, $options = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->all('".$model."', \$conditions, \$options);\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Store the ".$model."\n";
            $php .= "     *\n";
            $php .= "     * @param ".$alias.'  The '.$model." to be saved\n";
            $php .= "     * @param array \$options {\n";
            $php .= "     *   'ignore_relations' => bool  true: Only save the instance,  false: Save all connected instances,\n";
            $php .= "     *   'add_unknown_instance' => bool, false: Reject unknown instances. (use \$repository->create())\n";
            $php .= "     *   'reject_unknown_related_instances' => bool, false: Auto adds unknown instances\n";
            $php .= "     *   'keep_missing_related_instances' => bool, false: Auto deletes removed instances\n";
            $php .= "     * }\n";
            $php .= "     */\n";
            $php .= "    public function save".$model.'('.$instanceVar.', $options = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->save('".$model."', ".$instanceVar.", \$options);\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Create an in-memory ".$model.", ready to be saved.\n";
            $php .= "     *\n";
            $php .= "     * @param array \$values (optional) Initial contents of the object \n";
            $php .= "     * @return ".$alias."\n";
            $php .= "     */\n";
            $php .= "    public function create".$model.'($values = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->create('".$model."', \$values);\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Delete the ".$model."\n";
            $php .= "     *\n";
            $php .= "     * @param ".$alias.'|mixed '.$instanceVar.'  An '.$model.' or the '.$model." ID\n";
            $php .= "     */\n";
            $php .= "    public function delete".$model.'('.$instanceVar.')'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->delete('".$model."', ".$instanceVar.");\n";
            $php .= "    }\n";

            $php .= "    /**\n";
            $php .= "     * Reload the ".$model."\n";
            $php .= "     *\n";
            $php .= "     * @param ".$alias.'|mixed '.$instanceVar.'  An '.$model.' or the '.$model." ID\n";
            $php .= "     * @param array \$options  Additional options \n";
            $php .= "     */\n";
            $php .= "    public function reload".$model.'('.$instanceVar.', $options = [])'."\n";
            $php .= "    {\n";
            $php .= "        return \$this->reload('".$model."', ".$instanceVar.");\n";
            $php .= "    }\n";

            if ($config->plural !== $config->name) {
                $php .= "    /**\n";
                $php .= "     * Reload all ".$config->plural."\n";
                $php .= "     *\n";
                $php .= "     * @param array \$options  Additional options\n";
                $php .= "     */\n";
                $php .= "    public function reload".$config->plural.'()'."\n";
                $php .= "    {\n";
                $php .= "        return \$this->reload('".$model."', null, array('all' => true));\n";
                $php .= "    }\n";
            }
        }
        $php .= '}';
        
        $prefix = "<?php\n";
        $prefix .= "/**\n";
        $prefix .= ' * '.$class." a generated AutoCompleteHelper\n";
        $prefix .= " *\n";
        $prefix .= " */\n";
        if ($namespace !== null) {
            $prefix .= 'namespace '.$namespace.";\n\n";
        }
        
        return file_put_contents($filename, $prefix.$use.$php);
    }

    /**
     * Convert raw backend data into an object instance.
     *
     * @param mixed       $data
     * @param ModelConfig $config
     * @param string|null $index  (optional) speedoptim: Prevents resolving the index again.
     * @param bool        $reload true: Overwrite properties in the instance.
     *
     * @return stdClass
     */
    protected function convertToInstance($data, $config, $index = null, $reload = false)
    {
        if ($index === null) {
            $index = $this->resolveIndex($data, $config);
        } elseif (empty($this->objects[$config->name][$index])) {
            throw new Exception('Invalid index: "'.$index.'" for '.$config->name);
        }
        if ($reload) {
            $instance = $this->objects[$config->name][$index]['instance'];
            if ($instance === null) {
                throw new Exception('No instance loaded');
            }
        } elseif ($this->objects[$config->name][$index]['instance'] !== null) {
            throw new Exception('Instance already loaded, use reload parameter to reload');
        } else { // new instance
            $class = $config->class;
            $instance = new $class();
        }
        // Validate the properties in the class.
        if ($this->validated[$config->name] === false) { // No validated?
            $properties = get_object_vars($instance);
            $paths = array_merge($config->properties, $config->ignoreProperties, array_keys($config->belongsTo), array_keys($config->hasMany));
            foreach ($paths as $path) {
                $tokens = PropertyPath::parse($path);
                if (in_array($tokens[0][0], [PropertyPath::TYPE_ANY, PropertyPath::TYPE_ELEMENT])) {
                    unset($properties[$tokens[0][1]]);
                }
            }
            if (count($properties) !== 0) {
                $causes = [
                    '1. The column is missing in the backend/database.',
                    '2. The relation/foreign key is missing in the backend/database.',
                    '3. The column has diffent name than the property. Set the ModelConfig->properties[columname] = propertyname.',
                    '4. The property should be ignored by the repository. Add the property to the ModelConfig->ignoreProperties.',
                    '5. The relation couldn\'t be detected. Add an entry to ModelConfig->hasMany or ModelConfig->belongsTo.',
                ];
                throw new InfoException('Unexpected property: '.\Sledgehammer\quoted_human_implode(' and ', array_keys($properties)).' in '.$config->class.' class for "'.$config->name.'"', '<b>Possible causes:</b><br />'.implode('<br />', $causes));
            }
            $this->validated[$config->name] = true;
        }
        // Map the data onto the instance
        foreach ($config->properties as $sourcePath => $targetPath) {
            $value = PropertyPath::get($sourcePath, $data);
            if (isset($config->readFilters[$sourcePath])) {
                $value = \Sledgehammer\filter($value, $config->readFilters[$sourcePath]);
            }
            PropertyPath::set($targetPath, $value, $instance);
        }
        foreach ($config->belongsTo as $property => $relation) {
            if (isset($relation['convert'])) {
                $value = $this->convert($relation['model'], PropertyPath::get($relation['convert'], $data));
                PropertyPath::set($property, $value, $instance);
            } else {
                $belongsToId = $data[$relation['reference']];
                if ($belongsToId === null) {
                    PropertyPath::set($property, null, $instance);
                } else {
                    if (empty($relation['model'])) { // No model given?
                        throw new Exception('Invalid config: '.$config->name.'->belongsTo['.$property.'][model] not set');
                    }
                    if ($relation['useIndex']) {
                        $belongsToIndex = $this->resolveIndex($belongsToId);
                        $belongsToInstance = @$this->objects[$relation['model']][$belongsToIndex]['instance'];
                    } else {
                        $belongsToInstance = null;
                    }
                    if ($belongsToInstance !== null) {
                        $instance->$property = $belongsToInstance;
                    } else {
                        $fields = [
                            $relation['id'] => $belongsToId,
                        ];
                        $instance->$property = new BelongsToPlaceholder($this->ref().'/'.$config->name.'/'.$property, $instance, $fields);
                    }
                }
            }
        }
        foreach ($config->hasMany as $property => $relation) {
            if (isset($relation['convert'])) {
                $collection = new RepositoryCollection(PropertyPath::get($relation['convert'], $data), $relation['model'], $this->ref());
                PropertyPath::set($property, $collection, $instance);
            } else {
                $instance->$property = new HasManyPlaceholder($this->ref().'/'.$config->name.'/'.$property, $instance);
            }
        }
        $this->_triggerEvent($instance, 'load', $instance, ['repository' => $this->ref(), 'model' => $config->name], $this);

        return $instance;
    }

    /**
     * @param stdClass    $from   The instance
     * @param array       $to     The raw data
     * @param ModelConfig $config
     */
    protected function convertToData($instance, $config)
    {
        $to = [];
        $from = $instance;
        // Put the belongsTo columns at the beginning of the array
        foreach ($config->belongsTo as $property => $relation) {
            $to[$relation['reference']] = null;  // Dont set the value yet. (could be overwritten with an mapping?)
        }
        // Map to data
        foreach ($config->properties as $element => $property) {
            $value = PropertyPath::get($property, $from);
            if (isset($config->writeFilters[$element])) {
                $value = \Sledgehammer\filter($value, $config->writeFilters[$element]);
            }
            PropertyPath::set($element, $value, $to);
        }
        // Map the belongTo to the "*_id" columns.
        foreach ($config->belongsTo as $property => $relation) {
            if ($from->$property === null) {
                $to[$relation['reference']] = null;
            } else {
                $column = $relation['id'];
                $belongsToConfig = $this->_getConfig($relation['model']);
                $to[$relation['reference']] = PropertyPath::get($belongsToConfig->properties[$column], $from->$property);
            }
        }

        return $to;
    }

    /**
     * Add an configution for a model.
     *
     * @param ModelConfig $config
     */
    protected function registerModel($config)
    {
        if (isset($this->configs[$config->name])) {
            warning('Overwriting model: "'.$config->name.'"'); // @todo? Allow overwritting models? or throw Exception?
        }
        $this->collectionMappings[$config->name] = array_flip($config->properties); // Add properties to the collectionMapping
        if ($config->class === null) { // Detect class
            $config->class = false; // fallback to a generated class
            foreach ($this->namespaces as $namespace) {
                $class = $namespace.$config->name;
                if (class_exists($class)) { // Is the class known?
                    $config->class = $class;
                    break;
                }
            }
        }
        if ($config->class === false) { // Should registerBackand generate a class?
            if (empty(self::$instances['default']) || self::$instances['default'] !== $this) {
                $config->class = '\\Generated\\'.$this->ref().'\\'.$config->name; // Multiple Repositories have multiple namespaces
            } else {
                $config->class = '\\Generated\\'.$config->name;
            }
        }
        if (substr($config->class, 0, 1) !== '\\') {
            $config->class = '\\'.$config->class;
        }
        if ($config->plural === null) {
            $config->plural = Inflector::pluralize($config->name);
        }
        $this->configs[$config->name] = $config;
        $this->created[$config->name] = [];
        if (isset($this->plurals[$config->plural])) {
            warning('Overwriting plural['.$config->plural.'] "'.$this->plurals[$config->plural].'" with "'.$config->name.'"');
        }
        $this->plurals[$config->plural] = $config->name;
    }

    /**
     * Lookup a RepositoryBackend for a backendname.
     *
     * @param string $backend
     *
     * @return RepositoryBackend
     */
    protected function _getBackend($backend)
    {
        $backendObject = @$this->backends[$backend];
        if ($backendObject !== null) {
            return $backendObject;
        }
        throw new Exception('Backend "'.$backend.'" not registered');
    }

    /**
     * Lookup the ModelConfig for a modelname.
     *
     * @param string $model
     *
     * @return ModelConfig
     */
    protected function _getConfig($model)
    {
        $config = @$this->configs[$model];
        if ($config !== null) {
            return $config;
        }
        throw new InfoException('Unknown model: "'.$model.'"', ['Available models' => implode(array_keys($this->configs), ', ')]);
    }

    /**
     * Return the ($this->objects) index.
     *
     * @param mixed       $from   data, instance or an id string or array
     * @param ModelConfig $config
     *
     * @return string
     */
    protected function resolveIndex($from, $config = null)
    {
        if ((is_string($from) && $from != '') || is_int($from)) {
            return '{'.$from.'}';
        }
        $key = false;
        if (isset($config->id) && count($config->id) == 1) {
            $key = $config->id[0];
        }
        if (is_array($from)) {
            if (count($from) == 1 && $key !== false) {
                if (isset($from[$key])) {
                    return $this->resolveIndex($from[$key]);
                }
                $index = PropertyPath::get($key, $from);
                if ($index !== null) {
                    return '{'.$index.'}';
                }
                throw new Exception('Failed to resolve index, missing key: "'.$key.'"');
            }
            if (is_array($config->id)) {
                if (count($config->id) == 1) {
                    $field = $config->id[0];
                    if (isset($from[$key])) {
                        return $this->resolveIndex($from[$key]);
                    }
                    throw new Exception('Failed to resolve index, missing key: "'.$key.'"');
                }
                $index = '{';
                foreach ($config->id as $field) {
                    if (isset($from[$field])) {
                        $value = $from[$field];
                        if ((is_string($value) && $value != '') || is_int($value)) {
                            $index .= $field.':'.$value;
                        } else {
                            throw new Exception('Failed to resolve index, invalid value for: "'.$field.'"');
                        }
                    } else {
                        throw new Exception('Failed to resolve index, missing key: "'.$key.'"');
                    }
                }
                $index .= '}';

                return $index;
            }
        }
        if (is_object($from)) {
            if ($key !== false) {
                if (empty($config->properties[$key])) {
                    throw new Exception('ModelConfig->id is not mapped to the instances. Add ModelConfig->properties[name] = "'.$key.'"');
                }
                $id = PropertyPath::get($config->properties[$key], $from);
                if ($id === null) { // Id value not set?
                    foreach ($this->created[$config->name] as $index => $created) {
                        if ($from === $created) {
                            return $index;
                        }
                    }
                    throw new InfoException('Failed to resolve index, missing property: "'.$key.'"', $from);
                }

                return $this->resolveIndex($id);
            }
            throw new Exception('Not implemented');
        }
        throw new Exception('Failed to resolve index');
    }

    /**
     * Return the instance the Placeholder points to.
     *
     * @param BelongsToPlaceholder|Junction $wrapper
     * @param ModelConfig                   $config
     */
    protected function resolveInstance($wrapper, $config)
    {
        if (($wrapper instanceof BelongsToPlaceholder) === false && ($wrapper instanceof Junction) === false) {
            throw new Exception('Parameter $placeholder must be a BelongsToPlaceholder or Junction');
        }
        if ($wrapper instanceof Junction && PropertyPath::get($config->properties[$config->id[0]], $wrapper) === null) {
            foreach ($this->created[$config->name] as $index => $created) {
                $isAMatch = true;
                foreach (get_object_vars($created) as $property => $value) {
                    if ($wrapper->$property !== $value) {
                        $isAMatch = false;
                        break;
                    }
                }
                if ($isAMatch) {
                    return $created;
                }
            }
        }
        $index = $this->resolveIndex($wrapper, $config);
        if (empty($this->objects[$config->name][$index]['instance'])) {
            throw new Exception('Placeholder "'.$model.' '.$index.'" not loaded');
        }
        $instance = $this->objects[$config->name][$index]['instance'];
        foreach (get_object_vars($instance) as $property => $value) {
            if ($wrapper->$property !== $value) {
                throw new Exception('Placeholder belongs to another model');
            }
        }

        return $instance;
    }

    private function ref()
    {
        $ref = array_search($this, static::$instances, true);
        if ($ref === false) {
            $ref = uniqid('R');
            static::$instances[$ref] = $this;
        }

        return $ref;
    }

    private function _triggerEvent($instance, $event)
    {
        if ($instance && method_exists($instance, 'hasEvent') && method_exists($instance, 'trigger') && $instance->hasEvent($event)) { // Is an Observable?
            $params = func_get_args();
            array_shift($params);

            return call_user_func_array([$instance, 'trigger'], $params);
        }
    }
}
