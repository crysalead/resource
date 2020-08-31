<?php
namespace Lead\Resource\Chaos\JsonApi;

use IteratorAggregate;
use ArrayIterator;
use Chaos\ORM\Document;
use Chaos\ORM\Collection\Collection;
use Lead\Resource\ResourceException;
use Lead\Resource\Chaos\JsonApi\Payload;

/**
 * JSON-API handlers based on the Chaos Database Layer.
 */
trait JsonApiHandlers
{
    /**
     * Handlers for creating/loading the resource to pass as arguments of action methods.
     *
     * The following handlers load entities using the Chaos Database Layer.
     * If an action method don't have any handler, the default handler (i.e. key === 0) will be used.
     *
     * @see Lead\Resource\Controller::args()
     *
     * @return array
     */
    protected function _handlers()
    {
        return [
            'state'   => [$this, '_state'],
            'actions' => [
                'index' => [$this, '_index'],
                'view'  => [$this, '_view'],
                'edit'  => [$this, '_operation'],
                'delete'  => [$this, '_operation'],
                [$this, '_process']
            ]
        ];
    }

    /**
     * Handler for extracting the state of resource instances.
     *
     * @see Lead\Resource\Controller::state()
     *
     * @param  object $resource A resource instance.
     * @return array
     */
    protected function _state($resource)
    {
        $handlers = [
            'Chaos\ORM\Collection\Collection' => function($resource) {
                return ['exists' => true, 'valid' => true];
            },
            'Chaos\ORM\Model' => function($resource) {
                return ['exists' => $resource->exists(), 'valid' => !$resource->errors()];
            }
        ];
        if ($resource) {
            foreach ($handlers as $class => $handler) {
                if ($resource instanceof $class) {
                    return $handler($resource);
                }
            }
        }
        return [];
    }

    /**
     * Handler for generating arguments list for GET index method.
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _index($request, $options)
    {
        $model = $options['binding'];
        $conditions = $this->_fetchingConditions($model, $request);
        $query = $model::find(compact('conditions') + $this->_paging($request));
        $q = $request->query();
        $raw = isset($q['raw']) && filter_var($q['raw'], FILTER_VALIDATE_BOOLEAN);
        if ($raw && !empty($q['include'])) {
            throw new ResourceException("The `raw` parameter is not compatible with the `include` parameter as query parameters.", 422);
        }
        $query->fetchOptions(['return' => $raw ? 'array' : 'entity']);
        return [[null, $query, $this->_query($request)]];
    }

    /**
     * Handler for generating arguments list for GET view method.
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _view($request, $options)
    {
        $model = $options['binding'];
        $conditions = $this->_fetchingConditions($model, $request);

        if (empty($conditions[$this->_key])) {
            throw new ResourceException("Missing `{$this->name()}` resource `" . $this->_key . "`(s).", 422);
        }
        $query = $model::find(compact('conditions'));
        $collection = $query->all();
        if (!$collection->count()) {
            $keys = join(', ', $conditions[$this->_key]);
            throw new ResourceException("No `{$this->name()}` resource(s) found with value `{$keys}` as `" . $this->_key . "`, nothing to process.", 404);
        }
        foreach ($collection as $entity) {
            $list[] = [null, $entity, $this->_query($request)];
        }
        return $list;
    }

    /**
     * Handler for generating arguments list for POST, PUT, PATCH, DELETE methods (CRUDs operations) .
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _operation($request, $options)
    {
        $model = $options['binding'];
        $method = $request->method();
        $mime = $request->mime();
        $payload = null;

        if ($mime === 'application/json') {
            $body = $request->body();
            $isArray = isset($body[0]) && $body[0] === '[';
            $collection = $request->get();
            $collection = $isArray ? $collection : ($collection ? [$collection] : []);
        } elseif ($mime === 'application/vnd.api+json') {
            $payload = Payload::parse($request->body(), $this->_key);
            $collection = $payload->export(null);
        } else {
            $collection = $request->get();
        }

        if (!$collection) {
            throw new ResourceException("No data provided for `{$this->name()}` resource(s), nothing to process.", 422);
        }

        $keys = [];
        foreach ($collection as $data) {
            if (!empty($data[$this->_key])) {
                $keys[] = $data[$this->_key];
            }
        }

        $entityById = [];

        if ($method !== 'POST' && $keys) {
            $conditions[$this->_key] = $keys;
            $query = $model::find(compact('conditions'));
            $data = $query->all();
            foreach ($data as $entity) {
                $entityById[$entity[$this->_key]] = $entity;
            }
            if ($data->count() !== count($keys)) {
                $missingKeys = join(', ', array_diff($keys, array_keys($entityById)));
                throw new ResourceException("No `{$this->name()}` resource(s) found in database with `" . strtoupper($this->_key) . "`s `[{$missingKeys}]`, aborting.", 404);
            }
            if ($method !== 'PUT' && $data->count() !== count($collection)) {
                throw new ResourceException("Missing `{$this->name()}` resource(s) `" . strtoupper($this->_key) . "`s in payload use POST or PUT to create new resource(s).", 404);
            }
        } elseif ($method === 'PATCH' || $method === 'DELETE') {
            throw new ResourceException("Missing `{$this->name()}` resource(s) `" . strtoupper($this->_key) . "`(s) in payload.", 404);
        }

        $definition = $model::definition();
        $key = $definition->key();
        $resolver = new CidResolver();
        $collection = $resolver->resolve($collection, $model);

        foreach ($collection as $data) {
            $id = $data[$this->_key] ?? null;
            if (isset($entityById[$id])) {
                $list[] = [$method === 'DELETE' ? 'delete' : 'edit', $entityById[$id], $model::create([$key => $entityById[$id][$key]] + $data, ['exists' => true]), $payload];
            } elseif ($method === 'POST' || $method === 'PUT') {
                $instance = $model::create($data);
                $class = $instance->self();
                $list[] = ['add', $class::create(), $model::create($data), $payload];
            } else {
                throw new ResourceException("Missing `{$this->name()}` resource(s) `" . strtoupper($this->_key) . "`s in payload use POST or PUT to create new resource(s).", 404);
            }
        }
        return $list;
    }

    /**
     * Handler for generating arguments list for POST, PUT, PATCH, DELETE methods (CRUDs operations) .
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _process($request, $options)
    {
        $model = $options['binding'];
        $method = $request->method();
        if ($method === 'GET') {
            return [[null, $model, $method === 'GET' ? $request->query() : $request->get()]];
        }
        $body = $request->body();
        $mime = $request->mime();
        $list = [];
        if ($mime === 'application/json') {
            $payload = $request->get();
            $isArray = isset($body[0]) && $body[0] === '[';
            $collection = $isArray ? $payload : [$payload];
        } elseif ($mime === 'application/vnd.api+json') {
            $payload = $request->get();
            $payload = Payload::parse($request->body(), $this->_key);
            $collection = $payload->export(null);
            $collection = $collection ?: [];
        } else {
            $collection = [$request->get()];
        }

        $resolver = new CidResolver();
        $collection = $resolver->resolve($collection, $model);

        foreach ($collection as $data) {
                $list[] = [null, $model, $data, null];
        }
        return $list;
    }

    /**
     * Returns the resource id(s) condition to load the resource matching the route's params constraints.
     *
     * @param  string $model   A fully namespaced model class name.
     * @param  array  $request The request.
     * @return array           A list of resource id(s) matching constraints.
     */
    protected function _fetchingConditions($model, $request)
    {
        $params = $request->params();

        if (isset($params['id'])) {
            return [$this->_key => [$params['id']]];
        }
        if (!isset($params['relations'])) {
            return [];
        }

        $conditions = [];
        $definition = $model::definition();

        $relations = array_reverse($params['relations']);
        foreach ($relations as $key => $parts) {
            $rel = $definition->relation($parts[0]);
            if ($rel->type() !== 'belongsTo') {
                break;
            }
            unset($relations[$key]);
            $conditions[$rel->keys('from')] = $parts[1];
        }

        if (count($relations) === 1) {
            $parts = reset($relations);
            $rel = $definition->relation($parts[0]);
            $id = $parts[1];
            $conditions += $this->_relatedIds($rel, $id);
        } elseif ($relations) {
            throw new Exception('Invalid URL, only one has<One|Many|ManyThrough> relationship is allowed');
        }
        return $conditions;
    }

    /**
     * Returns the resource id(s) condition to load the resources depending of a specific has<One|Many|ManyThrough> relationship.
     *
     * @param  string $model   A fully namespaced model class name.
     * @param  array  $request The request.
     * @return array           A list of resource id(s) matching constraints.
     */
    protected function _relatedIds($rel, $id)
    {
        if ($rel->type() === 'hasManyThrough') {
            $model = $rel->from();
            $definition = $model::definition();
            $relThrough = $definition->relation($rel->through());
            $pivot = $relThrough->to();
            $relBelongsTo = $pivot::definition()->relation($rel->using());
            $to = $relBelongsTo->to();
            $entity = $to::first(['conditions' => [$this->_key => $id]]);

            $collection = $pivot::all([
                'conditions' => [
                    $relBelongsTo->keys('from') => $entity->{$relBelongsTo->keys('to')}
                ]
            ]);
            $rel = $relThrough;
        } else {
            $to = $rel->to();
            $collection = $to::all(['conditions' => [$this->_key => $id]]);
        }
        $keys = [];
        foreach ($collection as $entity) {
            $keys[] = $entity->{$rel->keys('to')};
        }
        if (!$keys) {
            throw new ResourceException("Relationships not found can't load the `{$this->name()}` resource.", 404);
        }
        return [$rel->keys('from') => $keys];
    }

    /**
     * Builds a query array from JSON-API query string.
     *
     * @param  array  $request The request.
     * @return array           The query array.
     */
    protected function _query($request)
    {
        $query = ['filter' => [], 'include' => []];
        $q = $request->query();

        if (isset($q['include'])) {
            $query['include'] = array_map('trim', explode(',', $q['include']));
        }
        if (isset($q['filter']) && is_array($q['filter'])) {
            foreach ($q['filter'] as $key => $value) {
                $query['filter'][$key] = strpos($value, ',') !== false ? array_map('trim', explode(',', $value)) : $value;
            }
        }
        return $query;
    }

    /**
     * Builds the paging array from JSON-API query string.
     *
     * @param  array  $request The request.
     * @return array           The query array.
     */
    protected function _paging($request)
    {
        $paging = [];
        $q = $request->query();

        if (isset($q['sort'])) {
            $orders = [];
            foreach (explode(',', $q['sort']) as $field) {
                if (substr($field, 0, 1) === '-') {
                    $orders[substr($field, 1)] = 'DESC';
                } else {
                    $orders[$field] = 'ASC';
                }
            }
            $paging['order'] = $orders;
        }
        if (isset($q['page'])) {
            $paging = $paging + array_intersect_key($q['page'], array_fill_keys(['limit', 'offset', 'page'], true));
        }
        return $paging;
    }

    /**
     * Fetches lazy-loadable resource instances by loading their data when it applies.
     *
     * @param  mixed $resource A resource instance.
     * @return mixed           A resolved resource instance.
     */
    protected function _fetch($resource)
    {
        if ($resource instanceof IteratorAggregate) {
            $query = $resource;
            $fetchOptions = $query->fetchOptions();
            $resource = $query->getIterator();
            if ($resource instanceof ArrayIterator && $query->statement()->data('limit')) {
                $this->_meta['count'] = $query->count();
            }
            $params = $this->request->params();
            if (isset($params['id'])) {
                if (!$resource = $resource->rewind()) {
                    $params = $this->request->params();
                    throw new ResourceException("Resource `{$this->name()}` has no `{$this->_key}` with value `{$params['id']}`.", 404);
                }
            }
            if ($fetchOptions['return'] === 'array') {
                $resource = iterator_to_array($resource);
            }
        }
        return $resource;
    }

    /**
     * Wraps passed parameter into a collection
     *
     * @param  Array  $resources An array of resources.
     * @return Object            The collection instance.
     */
    protected function _collection($resources)
    {
        if ($resources instanceof Collection) {
            return $resources;
        }
        $binding = $this->binding();
        return $binding::create($resources, ['type' => 'set']);
    }

    /**
     * Check document data
     *
     * @param  mixed   $resource The resource to check.
     * @return boolean
     */
    protected function _isDocument($resource)
    {
        return $resource instanceof Document;
    }

    /**
     * Check bulk data
     *
     * @param  mixed   $resource The resource to check.
     * @return boolean
     */
    protected function _isBulk($resource)
    {
        return is_array($resource) ? !$resource || isset($resource[0]) : !$resource instanceof Document;
    }
}
