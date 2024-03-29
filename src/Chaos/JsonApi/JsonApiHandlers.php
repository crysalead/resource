<?php
namespace Lead\Resource\Chaos\JsonApi;

use IteratorAggregate;
use ArrayIterator;
use Lead\Set\Set;
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
                'edit'  => [$this, '_edit'],
                'delete'  => [$this, '_delete'],
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
    protected function _index($request, $options, &$validationErrors = [])
    {
        $model = $options['binding'];
        $conditions = $this->_fetchingConditions($model, $request);

        $queryArgs = $this->_queryParameters($request, $model);
        $q = $request->query();
        $raw = isset($q['raw']) && filter_var($q['raw'], FILTER_VALIDATE_BOOLEAN);
        $raw = $raw ?: isset($queryArgs['return']) && $queryArgs['return'] === 'array';

        if ($raw && !empty($queryArgs['include'])) {
            throw new ResourceException("The `raw` parameter is not compatible with the `include` parameter as query parameters.", 422);
        }

        $order = isset($q['sort']) ? $this->_sort($request) : (!empty($queryArgs['query']['order']) ? array_merge(...$queryArgs['query']['order']) : []);

        $query = $model::find(compact('conditions') + $this->_paging($queryArgs));
        $query->order($order);
        $query->fetchOptions(['return' => $raw ? 'array' : 'entity']);
        return [[null, $query, $queryArgs]];
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
    protected function _view($request, $options, &$validationErrors = [])
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

        $queryArgs = $this->_queryParameters($request, $model);
        foreach ($collection as $entity) {
            $list[] = [null, $entity, $queryArgs];
        }
        return $list;
    }

    /**
     * Handler for generating arguments list for POST, PUT, PATCH methods (CRUDs operations) .
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _edit($request, $options, &$validationErrors = [])
    {
        $model = $options['binding'];
        $method = $request->method();
        $list = [];

        [$payload, $collection, $entityById] = $this->_fetchRequestData($model, $request, $validationErrors);

        $restricted = $this->_requestRules->restrictedValues($request->format(), $this->_action);
        $restrictedIncludes = $restricted['data']['relationships'] ?? [];

        if ($payload && $notAllowed = array_diff($payload->embedded(), $restrictedIncludes)) {
            throw new ResourceException("Resource `{$this->name()}` does not allow the following include `[" . join(',', $notAllowed) . "]`.", 422);
        }

        $definition = $model::definition();
        $key = $definition->key();

        $queryArgs = $this->_queryParameters($request, $model);

        foreach ($collection as $i => $data) {
            $id = $data[$this->_key] ?? null;
            if (isset($entityById[$id])) {
                $list[] = ['edit', $entityById[$id], $model::create([$key => $entityById[$id][$key]] + $data, ['exists' => true, 'defaults' => false]), $queryArgs, $payload];
            } else {
                $instance = $model::create($data);
                $class = $instance->self();
                $list[] = ['add', $class::create(), $model::create($data), $queryArgs, $payload];
            }
        }
        return $list;
    }

    /**
     * Handler for generating arguments list for DELETE methods (CRUDs operations) .
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _delete($request, $options, &$validationErrors = [])
    {
        $model = $options['binding'];
        $method = $request->method();
        $list = [];
        $conditions = $this->_fetchingConditions($model, $request);
        $payload = null;
        $entityById = [];

        $queryArgs = $this->_queryParameters($request, $model);

        $truncate = !empty($queryArgs['_truncate_']);

        if ($conditions) {
            foreach ($model::all(compact('conditions')) as $entity) {
                $entityById[$entity[$this->_key]] = $entity;
            }
        } elseif ($request->body()) {
            [$payload, $collection, $entityById] = $this->_fetchRequestData($model, $request, $validationErrors);
        } else {
            if (!$conditions && !$truncate) {
                throw new ResourceException("No valid filters provided for `{$this->name()}` resource(s), nothing to process.", 422);
            }

            foreach ($model::all(compact('conditions')) as $entity) {
                $entityById[$entity[$this->_key]] = $entity;
            }
        }

        foreach ($entityById as $entity) {
            $list[] = ['delete', $entity, $queryArgs, $payload];
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
    protected function _process($request, $options, &$validationErrors = [])
    {
        $model = $options['binding'];
        $method = $request->method();
        if ($method === 'GET' || $method === 'FETCH') {
            return [[null, $model, $this->_queryParameters($request, $model)]];
        }
        $body = $request->body();
        $mime = $request->mime();
        $list = [];
        $resolveCid = true;
        if ($mime === 'application/json') {
            $payload = $request->get();
            $isArray = isset($body[0]) && $body[0] === '[';
            $collection = $isArray ? $payload : [$payload];
        } elseif ($mime === 'application/vnd.api+json') {
            $payload = $request->get();
            $payload = Payload::parse($request->body(), $this->_key);
            $collection = $payload->export(null);
            $collection = $collection ?: [];
        } elseif ($mime === 'text/csv') {
            $collection = [$request->get()];
        } else {
            $collection = [$request->get()];
            $resolveCid = false;
        }

        if ($resolveCid) {
            $collection = $this->_resolveCid($collection, $model, $validationErrors);
        }

        if (array_filter($validationErrors)) {
            return [];
        }

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
            $q = $request->query();
            $key = isset($q['key']) && $q['key'] === 'cid' ? 'cid' : $this->_key;
            return [$key => [$params['id']]];
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
            throw new ResourceException('Invalid URL, only one has<One|Many|ManyThrough> relationship is allowed');
        }
        return $conditions;
    }

    /**
     * Returns the request data collection.
     *
     * @param  string $model            A fully namespaced model class name.
     * @param  array  $request          The request.
     * @param  array  $validationErrors Will contain the occured errors.
     * @return array                    A list of resources.
     */
    protected function _fetchRequestData($model, $request, &$validationErrors)
    {
        $method = $request->method();
        $payload = null;
        $mime = $request->mime();
        if ($mime === 'application/json') {
            $body = $request->body();
            $isArray = isset($body[0]) && $body[0] === '[';
            $collection = $request->get(['model' => $model]);
            $collection = $isArray ? $collection : ($collection ? [$collection] : []);
        } elseif ($mime === 'application/vnd.api+json') {
            $payload = Payload::parse($request->body(), $this->_key);
            $collection = $payload->export(null);
        } else {
            $collection = $request->get(['model' => $model]);
        }

        if (!$collection) {
            throw new ResourceException("Invalid request body, nothing to process.", 422);
        }

        $collection = $this->_resolveCid($collection, $model, $validationErrors);

        $keys = [];
        foreach ($collection as $i => $data) {
            if (!empty($data[$this->_key])) {
                $keys[] = $data[$this->_key];
                $validationErrors[$i] = $validationErrors[$i] ?? null;
            } elseif ($method !== 'POST' && $method !== 'PUT') {
                $validationErrors[$i] = $validationErrors[$i] ?? [$this->_key => ["Missing `{$this->name()}` `" . $this->_key . "`s in payload use POST or PUT to create new resource(s)."]];
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
            foreach ($collection as $i => $data) {
                $id = $data[$this->_key] ?? null;
                if (!isset($entityById[$id]) && $method !== 'POST' && $method !== 'PUT') {
                    $validationErrors[$i] = $validationErrors[$i] ?? [$this->_key => ["No `{$this->name()}` resource(s) found in database with `" . $this->_key . "`s `[{$id}]`, aborting."]];
                }
            }
        }

        return [$payload, $collection, $entityById];
    }

    /**
     * Resolve cid.
     *
     * @param  string $model            A fully namespaced model class name.
     * @param  array  $request          The request.
     * @param  array  $validationErrors Will contain the occured errors.
     * @return array                    A list of resources with cid resolved.
     */
    protected function _resolveCid($collection, $model, &$validationErrors) {
        $resolver = new CidResolver();
        return $resolver->resolve($collection, $model, $validationErrors);
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
    protected function _queryParameters($request, $model)
    {
        $method = $request->method();
        $body = $request->body();

        $q = $request->query();

        if (!empty($q['include'])) {
            $q['include'] = array_map('trim', explode(',', $q['include']));
        }

        if (!empty($q['filter'])) {
            $q['filter'] = $this->_requestRules->parseFilter($q['filter'] ?: [], $model);
        }

        if (isset($q['_truncate_'])) {
            $q['_truncate_'] = filter_var($q['_truncate_'], FILTER_VALIDATE_BOOLEAN);
        }

        if (!empty($q['query'])) {
            $q['query'] = json_decode($q['query'], true);
        } elseif (($method === 'GET' || $method === 'FETCH') && $body) {
            $q += $request->get();
            if (!empty($q['include'])) {
                $q['query']['embed'] = $q['include'];
            }
        }
        if (!empty($q['query']['conditions'])) {
            $keys = $this->_arrayKeysMulti(($q['query']['conditions']));
            foreach ($keys as $key) {
                if ($key === ':plain') {
                    throw new ResourceException("Resource `{$this->name()}` doesn't allow `:plain` in queries conditions.", 403);
                }
            }
        }
        $this->_requestRules->check($request->format(), $this->_action, $q, []);
        return $q;
    }

    protected function _arrayKeysMulti($array) {
        $keys = array();
        foreach ($array as $key => $value) {
            $keys[] = $key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->_arrayKeysMulti($value));
            }
        }
        return $keys;
    }

    /**
     * Builds the order array from JSON-API query string.
     *
     * @param  array  $request The request.
     * @return array           The query array.
     */
    protected function _sort($request)
    {
        $orders = [];
        $q = $request->query();
        if (isset($q['sort'])) {
            foreach (explode(',', $q['sort']) as $field) {
                if (substr($field, 0, 1) === '-') {
                    $orders[substr($field, 1)] = 'DESC';
                } else {
                    $orders[$field] = 'ASC';
                }
            }
        }
        return $orders;
    }

    /**
     * Builds the paging array from JSON-API query string.
     *
     * @param  array  $queryArgs The query parameters.
     * @return array     The query array.
     */
    protected function _paging($queryArgs)
    {
        $paging = [];
        if (isset($queryArgs['page'])) {
            $paging = array_intersect_key($queryArgs['page'], array_fill_keys(['limit', 'offset', 'page'], true));
        } elseif (isset($queryArgs['query'])) {
            $paging = array_intersect_key($queryArgs['query'], array_fill_keys(['limit', 'offset', 'page'], true));
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
     * Check set data
     *
     * @param  mixed   $resource The resource to check.
     * @return boolean
     */
    protected function _isSet($resource)
    {
        return is_array($resource) ? !$resource || isset($resource[0]) : !$resource instanceof Document;
    }
}
