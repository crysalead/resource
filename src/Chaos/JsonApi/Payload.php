<?php
namespace Lead\Resource\Chaos\JsonApi;

use Exception;
use Lead\Inflector\Inflector;
use Lead\Net\Http\Media;
use Lead\Resource\ResourceException;
use Chaos\ORM\Model;
use Chaos\ORM\Collection\Collection;
use Chaos\ORM\Collection\Through;

/**
 * JSON-API payload.
 */
class Payload
{
    /**
     * Default entity's primary key name.
     *
     * @var string
     */
    protected $_key = 'id';

    /**
     * Entity's primary key name per type.
     * The `$_key` value will be used for undefined type.
     *
     * example: `['post' => 'uid', 'comments' => '_id']`
     *
     * @var array
     */
    protected $_keys = [];

    /**
     * Keys cache
     *
     * @var array
     */
    protected $_indexed = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_jsonapi = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_meta = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_links = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_data = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_errors = [];

    /**
     * Store validation errors
     *
     * @var array
     */
    protected $_validationErrors = [];

    /**
     * @see http://jsonapi.org/format/
     *
     * @var array
     */
    protected $_included = [];

    /**
     * Indexes included data using type & id.
     *
     * @var array
     */
    protected $_store = [];

    /**
     * Exported JSON-API items into a nested form array.
     *
     * @see Payload::export()
     *
     * @var array
     */
    protected $_relationships = [];

    /**
     * Link generator handler.
     *
     * @var callable
     */
    protected $_link = null;

    /**
     * Data importer handler.
     */
    protected $_importer = null;

    /**
     * Constructor.
     *
     * @param array $config The config array
     */
    public function __construct($config = [])
    {
        $defaults = [
            'key'    => 'id',
            'keys'   => [],
            'data'   => [],
            'link'   => null,
            'importer' => function($entity) {
                return $entity->to('array', ['embed' => false]);
            },
            'exporter' => function($model, $data, $options) {
                if (!$model) {
                    return $data;
                }
                return $model::create($data, $options);
            }
        ];

        $config += $defaults;

        $config['data'] += [
            'jsonapi'  => [],
            'meta'     => [],
            'links'    => [],
            'data'     => [],
            'errors'   => [],
            'included' => []
        ];

        $this->_key = $config['key'];
        $this->_keys = $config['keys'];
        $this->_link = $config['link'];
        $this->_importer = $config['importer'];
        $this->_exporter = $config['exporter'];

        $this->jsonapi($config['data']['jsonapi']);
        $this->meta($config['data']['meta']);
        $this->links($config['data']['links']);
        $this->data($config['data']['data']);
        $this->errors($config['data']['errors']);

        foreach($config['data']['included'] as $include) {
            $this->_store($include);
        }
    }

    /**
     * Indexes an item according its type & id into `$_store`.
     *
     * @param array $data The item data to store.
     */
    protected function _store($data)
    {
        if (!isset($data['id'])) {
            return;
        }
        $id = $data['id'];
        $type = $data['type'];
        $attributes = isset($data['attributes']) ? $data['attributes'] : [];
        $key = isset($this->_keys[$type]) ? $this->_keys[$type] : $this->_key;
        $this->_store[$type][$id] = [$key => $id] + $attributes;
        if (isset($data['relationships'])) {
            $this->_relationships[$type][$id] = $data['relationships'];
        }
        $this->_included[] = $data;
    }

    /**
     * Checks whether the payload is a collection or not.
     *
     * @return boolean
     */
    public function isCollection()
    {
        return count($this->_data) !== 1;;
    }

    /**
     * Sets a resource as the payload.
     *
     * @param  mixed   $resource The Chaos entity/collection to set as payload.
     * @return self
     */
    public function set($resource)
    {
        $this->_validationErrors = [];
        if ($resource instanceof Collection) {
            $this->meta($resource->meta());
            foreach ($resource as $entity) {
                $this->push($entity);
            }
            return $this;
        }
        $this->push($resource);
        return $this;
    }

    /**
     * Adds an entity in the payload.
     *
     * @param  object  $entity The Chaos entity to push in the payload.
     * @return self
     */
    public function push($entity)
    {
        $data = $this->_push($entity);
        if ($data === null) {
            return;
        }
        $this->_data[] = $data;
        $this->_storeValidationError($entity);

        if ($this->_exists($entity)) {
            end($this->_data);
            $this->_indexed[$entity->id()] = key($this->_data);
            reset($this->_data);
        }
        return $this;
    }

    /**
     * Wrap the model exists method.
     * Assume a `false` existance when the exists value can't be determined.
     *
     * @param  object  $entity The Chaos entity to check.
     * @return boolean
     */
    public function _exists($entity)
    {
        try {
            return $entity->exists();
        } catch(Exception $e) {
            return false;
        }
    }

    /**
     * Helper for `Payload::push()`.
     *
     * @param  object  $entity       The Chaos entity to push in the payload.
     * @param  boolean $relationship Indicates whether the entity is some related data or not.
     * @return array                 The pushed data
     */
    public function _push($entity, $related = false)
    {
        if (!$entity instanceof Model) {
            $this->errors([[
                'status' => 500,
                'code'   => 500,
                'title'  => "The JSON-API serializer only supports Chaos entities.",
            ]]);
            return;
        }
        $definition = $entity::definition();
        $data = $this->_data($entity);

        if (($link = $this->_link) && $this->_exists($entity)) {
            $data['links']['self'] = $link($data['type'], ['id' => $entity->id()], ['absolute' => true]);
        }

        if ($related) {
            $this->_store($data);
            unset($data['attributes']);
            unset($data['relationships']);
            unset($data['links']);
        }

        return $data;
    }

    /**
     * Store validation errors.
     *
     * @param  object  $entity     The Chaos entity.
     */
    public function _storeValidationError($entity)
    {
        if (!$errors = $entity->errors()) {
            $this->_validationErrors[] = null;
            return;
        }
        $this->_validationErrors[] = $errors;
    }

    /**
     * Helper for `Payload::push()`. Populates data's relationships
     *
     * @param  object  $entity     The Chaos entity.
     * @param  array   $relations  The Chaos relations to process.
     * @param  array   $data       The data array to be populated.
     */
    protected function _populateRelationships($entity, $relations, &$data)
    {
        $through = [];

        foreach ($relations as $name) {
            $this->_populateRelationship($entity, $name, $data, $through);
        }
        if (isset($data['relationships'])) {
            $data['relationships'] = array_filter($data['relationships']);
        }
        foreach($through as $rel) {
            unset($data['attributes'][$rel->through()]);
        }
    }

    /**
     * Helper for `Payload::push()`. Populates one relationship data.
     *
     * @param  object  $entity     The Chaos entity.
     * @param  object  $name       The name of the relationship to process.
     * @param  array   $data       The data array to be populated.
     * @param  array   $through    The through array to be populated with pivot tables.
     */
    protected function _populateRelationship($entity, $name, &$data, &$through)
    {
        if (!$entity->has($name)) {
            return;
        }
        if (!$child = $entity->{$name}) {
            return;
        }

        // Remove the `related` support for now, useless and Having issue with Single Table Inheritance.
        // if ($link = $this->_link) {
        //     $data['relationships'][$name]['links']['related'] = $this->_relatedLink($entity::definition()->relation($name)->counterpart()->name(), $entity->id(), $child);
        // }
        if ($child instanceof Model) {
            $data['relationships'][$name]['data'] = $this->_push($child, $this->_exists($child));
        } else {
            $isThrough = $child instanceof Through;
            if ($isThrough) {
                $through[] = $entity::definition()->relation($name);
            }
            if (!$isThrough) {
                $data['relationships'][$name]['data'] = [];
                foreach ($child as $item) {
                    $data['relationships'][$name]['data'][] = $this->_push($item, $this->_exists($item));
                }
            }
            if (isset($data['relationships'][$name]['data'])) {
                $data['relationships'][$name]['data'] = array_filter($data['relationships'][$name]['data']);
            }
        }
    }

    /**
     * Creates a related link.
     *
     * @param  string $relation The relation name.
     * @param  string $id       The relation id.
     * @return string $resource The resource name.
     */
    protected function _relatedLink($relation, $id, $resource)
    {
        $link = $this->_link;
        return $link($this->_name($resource), [
            'relation' => $relation,
            'rid' => $id
        ], ['absolute' => true]);
    }

    /**
     * Extracts the resource name from an instance.
     *
     * @param object $instance The entity instance.
     * @param string           The Resource name
     */
    protected function _name($instance)
    {
        $model = $instance->self();
        return substr(strrchr($model, '\\'), 1);
    }

    /**
     * Returns entity's data using the JSON-API format.
     *
     * @param  object  $entity     The Chaos entity.
     * @param  boolean $attributes Extract entities attributes or not.
     * @return array               The JSON-API formatted data.
     */
    protected function _data($entity, $attributes = true)
    {
        $definition = $entity::definition();
        $key = $entity::definition()->key();
        $result = ['type' => Inflector::camelize($definition->source())];

        $id = $entity->id();
        if ($id !== null) {
            $result['id'] = $id;
            $result['exists'] = $entity->exists();
        } else {
            $result['exists'] = false;
        }

        if (!$attributes) {
            return $result;
        }

        $attrs = [];
        $importer = $this->_importer;
        $data = $importer($entity);
        foreach ($data as $name => $value) {
            $attrs[$name] = $value;
        }
        unset($attrs[$key]);

        $result['attributes'] = $attrs;

        if ($relations = $definition->relations()) {
            $this->_populateRelationships($entity, $relations, $result);
        }

        return $result;
    }

    /**
     * Sets a resource to delete as payload.
     *
     * @param  mixed $resource The Chaos entity/collection to set as delete payload.
     * @return self
     */
    public function delete($resource)
    {
        if ($resource instanceof Collection) {
            $this->meta($resource->meta());
            foreach ($resource as $entity) {
                $this->_data[] = $this->_data($entity, false);
            }
            return $this;
        }
        $this->_data[] = $this->_data($resource, false);
        return $this;
    }

    /**
     * Returns all IDs from the payload.
     *
     * @return array
     */
    public function keys()
    {
        return array_keys($this->_indexed);
    }

    /**
     * Exports a JSON-API item data into a nested from array.
     */
    public function export($id = null, $model = null)
    {
        if ($id === null) {
            $collection = $this->data();
            $collection = count($this->_data) === 1 ? [$collection] : $collection;
        } else {
            if (!isset($this->_indexed[$id])) {
                throw new ResourceException("Unexisting data entry for id {$id} in the JSON-API payload.");
            }
            $collection = [$this->_data[$this->_indexed[$id]]];
        }
        $export = [];
        $options = [];
        foreach ($collection as $data) {
            $type = isset($data['type']) ? $data['type'] : null;
            $key = isset($this->_keys[$type]) ? $this->_keys[$type] : $this->_key;

            if (isset($data['id'])) {
                $result = [$key => $data['id']];
                $indexes = [$type => [$data['id'] => true]];
            } else {
                $result = [];
                $indexes = [];
            }
            $options['exists'] = !empty($data['exists']);

            if (isset($data['attributes'])) {
                $result += $data['attributes'];
            }
            $exporter = $this->_exporter;
            $result = $exporter($model, $result, $options);

            $schema = $model ? $model::definition() : null;

            if (isset($data['relationships'])) {
                foreach ($data['relationships'] as $key => $value) {
                    $to = $schema ? $schema->relation($key)->to() : null;
                    $result[$key] = $this->_relationship($value['data'], $indexes, $to);
                }
            }
            $export[] = $result;
        }
        return $id === null ? $export : reset($export);
    }

    /**
     * Helper for `Payload::export()`.
     */
    protected function _relationship($collection, &$indexes, $model)
    {
        $isCollection = !$collection || isset($collection[0]);
        $collection = $isCollection ? $collection : [$collection];
        $export = [];
        $options = [];
        $exporter = $this->_exporter;
        $schema = $model ? $model::definition() : null;
        foreach ($collection as $data) {
            $options['exists'] = !empty($data['exists']);
            if (isset($data['id'])) {
                if (isset($indexes[$data['type']][$data['id']])) {
                    continue;
                }
                $indexes[$data['type']][$data['id']] = true;
                if (!isset($this->_store[$data['type']][$data['id']])) {
                    continue;
                }
                $result = $this->_store[$data['type']][$data['id']];
                $relationships = isset($this->_relationships[$data['type']][$data['id']]) ? $this->_relationships[$data['type']][$data['id']] : [];
            } else {
                $result = isset($data['attributes']) ? $data['attributes'] : [];
                $relationships = isset($data['relationships']) ? $data['relationships'] : [];
            }

            foreach ($relationships as $key => $value) {
                $to = $schema ? $schema->relation($key)->to() : null;
                if ($item = $this->_relationship($value['data'], $indexes, $to)) {
                    $result[$key] = $item;
                }
            }
            $export[] = $exporter($model, $result, $options);
        }
        return $isCollection ? $export : reset($export);
    }

    /**
     * Gets/sets the `'jsonapi'` property.
     *
     * @return array
     */
    public function jsonapi($jsonapi = [])
    {
        if (!func_num_args()) {
            return $this->_jsonapi;
        }
        $this->_jsonapi = $jsonapi;
        return $this;
    }

    /**
     * Gets/sets the `'meta'` property.
     *
     * @return array
     */
    public function meta($meta = [])
    {
        if (!func_num_args()) {
            return $this->_meta;
        }
        $this->_meta = $meta;
        return $this;
    }

    /**
     * Gets/sets the `'links'` property.
     *
     * @return array
     */
    public function links($links = [])
    {
        if (!func_num_args()) {
            return $this->_links;
        }
        $this->_links = $links;
        return $this;
    }

    /**
     * Gets/sets the `'data'` property.
     *
     * @return array
     */
    public function data($data = [])
    {
        if (!func_num_args()) {
            return count($this->_data) === 1 ? reset($this->_data) : $this->_data;
        }
        if ($data && !isset($data[0])) {
            $data = [$data];
        }
        $this->_data = $data;
        foreach ($data as $key => $value) {
            if (isset($value['id'])) {
                $this->_indexed[$value['id']] = $key;
            }
        }
        return $this;
    }

    /**
     * Gets/sets the `'errors'` property.
     *
     * @return array
     */
    public function errors($errors = [])
    {
        if (func_num_args()) {
            $this->_errors = $errors;
            return $this;
        }
        $errors = $this->_errors;
        if (array_filter($this->_validationErrors)) {
            $errors[] = [
                'status' => 422,
                'code'   => 0,
                'title'  => 'Validation Error',
                'meta'   => $this->_validationErrors
            ];
        }
        return $errors;
    }

    /**
     * Gets/sets the `'included'` property.
     *
     * @return array
     */
    public function included($included = [])
    {
        if (!func_num_args()) {
            return $this->_included;
        }
        $this->_included = $included;
        return $this;
    }

    /**
     * Serializes the payload.
     *
     * @return string The payload string.
     */
    public function serialize()
    {
        $payload = array_filter([
            'jsonapi' => $this->jsonapi(),
            'meta'    => $this->meta(),
            'links'   => $this->links(),
        ]);
        if ($this->errors()) {
            $payload['errors'] = $this->errors();
        } else {
            $payload['data'] = $this->data();
            if ($this->included()) {
                $payload['included'] = $this->included();
            }
        }
        return $payload;
    }

    /**
     * Reset the payload.
     */
    public function reset()
    {
        $this->_indexed = [];
        $this->_jsonapi = [];
        $this->_meta = [];
        $this->_links = [];
        $this->_data = [];
        $this->_errors = [];
        $this->_validationErrors = [];
        $this->_included = [];
        $this->_store = [];
        $this->_relationships = [];
    }

    /**
     * Parses a JSON-API payload string.
     *
     * @return object The payload object.
     */
    public static function parse($payload, $key = 'id', $keys = [])
    {
        if (!$payload) {
            $data = [];
        } elseif (is_string($payload)) {
            $data = Media::decode('json', $payload);
        } else {
            $data = $payload;
        }
        return new static(['data' => $data, 'key' => $key, 'keys' => $keys]);
    }
}
