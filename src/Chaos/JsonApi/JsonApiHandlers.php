<?php
namespace Lead\Resource\Chaos\JsonApi;

use IteratorAggregate;
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
                [$this, '_operation'],
                'index' => [$this, '_get'],
                'view'  => [$this, '_get'],
                'add'   => [$this, '_post']
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
            'Chaos\Collection' => function($resource) {
                $exists = $resource->invoke('exists');
                $validates = $resource->invoke('validates');
                return ['exists' => $resource->exists(), 'valid' => $resource->validates()];
            },
            'Chaos\Model' => function($resource) {
                return ['exists' => $resource->exists(), 'valid' => $resource->validates()];
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
     * Handler for generating arguments list for PUT, PATCH, DELETE methods.
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
        $conditions = $this->_keys($model, $request);
        $payload = Payload::parse($request->body());

        if (!isset($conditions[$this->_key])) {
            $conditions[$this->_key] = $payload->keys();
        }
        if (empty($conditions[$this->_key])) {
            throw new ResourceException("Missing `{$this->name()}` resource `" . $this->_key . "`(s).", 422);
        }
        $query = $model::find(compact('conditions'));
        $collection = $query->all();
        if (!$collection->count()) {
            $keys = join(', ', $conditions[$this->_key]);
            throw new ResourceException("No `{$this->name()}` resource(s) found with value `[{$keys}]`, nothing to process.", 404);
        }
        foreach ($collection as $entity) {
            $list[] = [$entity, $payload->export((string) $entity->id()), $payload];
        }
        return $list;
    }

    /**
     * Handler for generating arguments list for GET methods.
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _get($request, $options)
    {
        $params = $request->params();
        $model = $options['binding'];
        $conditions = $this->_keys($model, $request);
        $query = $this->_query($request);
        $query = $model::find(compact('conditions') + $query);

        if (isset($params['id'])) {
            if (!$resource = $query->first()) {
                throw new ResourceException("Resource `{$this->name()}` has no `{$this->_key}` with value `{$params['id']}`.", 404);
            }
        } else {
            $resource = $query;
        }
        return [[ $resource ]];
    }

    /**
     * Handler for generating arguments list for POST methods.
     *
     * @see Lead\Resource\Controller::args()
     *
     * @param  object $request The request instance.
     * @param  array  $options An options array.
     * @return array
     */
    protected function _post($request, $options)
    {
        $payload = Payload::parse($request->body());
        if (!$collection = $payload->export()) {
            throw new ResourceException("No data provided for `{$this->name()}` resource(s), nothing to process.", 422);
        }
        $list = [];
        foreach ($collection as $data) {
            $list[] = [$options['binding']::create($data), $payload];
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
    protected function _keys($model, $request)
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
            throw new ResourceException("No valid relationship has been found for `{$this->name()}` resource.", 404);
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
        $query = [];
        $q = $request->query();

        if (isset($q['include'])) {
            $query['embed'] = explode(',', $q['include']);
        }
        if (isset($q['sort'])) {
            $orders = [];
            foreach (explode(',', $q['sort']) as $field) {
                if (substr($field, 0, 1) === '-') {
                    $orders[substr($field, 1, 0)] = 'DESC';
                } else {
                    $orders[$field] = 'ASC';
                }
            }
            $query['order'] = $orders;
        }
        if (isset($q['page'])) {
            $query = $query + array_intersect_key($q['page'], array_fill_keys(['limit', 'offset', 'page'], true));
        }
        if (isset($q['filter']) && is_array($q['filter'])) {
            foreach ($q['filter'] as $key => $value) {
                $query['filter'][$key] = strpos($value, ',') !== false ? explode(',', $value) : $value;
            }
        }
        return $query;
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
            return $resource->getIterator();
        }
        return $resource;
    }
}
