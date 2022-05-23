<?php
namespace Lead\Resource\Chaos\JsonApi;

use Lead\Resource\ResourceException;

class CidUnresolver
{
    /**
     * Indexes included data using type & id.
     *
     * @var array
     */
    protected $_store = [];

    public function unresolve($collection, $model, &$validationErrors = [])
    {
        $this->_ingest($collection, $model);
        $this->_fetchData();
        $definition = $model::definition();
        $key = $definition->key();
        $result = [];
        $relations = [];
        foreach ($definition->relations() as $name) {
            $relations[$name] = $definition->relation($name);
        }
        $replacer = function($model, $oldKey, $newKey, $i, &$data, &$validationErrors) {
            $id = $data[$oldKey];
            $cid = $this->_store[$model][$id] ?? null;
            if ($id && $cid === null) {
                $name = basename(str_replace('\\', '/', $model));
                $validationErrors[$i] = $validationErrors[$i] ?? [$oldKey => ["No `{$name}` resource(s) found with value `{$id}` as `id`."]];
            }
            $data[$newKey] = $cid;
            $definition = $model::definition();
            if (!$definition->has($oldKey)) {
                unset($data[$oldKey]);
            }
        };

        foreach ($collection as $i => $data) {
            unset($data[$key]);
            $validationErrors[$i] = $validationErrors[$i] ?? null;
            foreach ($relations as $key => $relation) {
                $to = $relation->to();
                if ($relation->type() === 'belongsTo') {
                    $fieldName = key($relations[$key]->keys());
                    $definitionTo = $to::definition();
                    if ($definitionTo->has('cid')) {
                        $replacer($to, $fieldName, $key . 'Cid', $i, $data, $validationErrors);
                    }
                }
                if (!empty($data[$key])) {
                    $value = $data[$key];
                    $resolved = $this->resolve($relation->isMany() ? $value : [$value], $to);
                    $data[$key] = $relation->isMany() ? $resolved : reset($resolved);
                }
            }
            $result[] = $data;
        }
        return $result;
    }

    protected function _ingest($collection, $model)
    {
        $definition = $model::definition();
        $relations = [];
        foreach ($definition->relations() as $name) {
            $relations[$name] = $definition->relation($name);
        }
        foreach ($collection as $data) {
            foreach ($relations as $key => $relation) {
                $to = $relation->to();
                if ($relation->type() === 'belongsTo') {
                    $fieldName = key($relations[$key]->keys());
                    $definitionTo = $to::definition();
                    if (!$definitionTo->has('cid')) {
                        continue;
                    }
                    if (isset($data[$fieldName])) {
                        $this->_store[$to][$data[$fieldName]] = null;
                    }
                }
                if (!empty($data[$key])) {
                    $value = $data[$key];
                    $this->_ingest($relation->isMany() ? $value : [$value], $relation->to());
                }
            }
        }
    }

    protected function _fetchData()
    {
        foreach ($this->_store as $model => $ids) {
            unset($ids['']);
            if (!$ids) {
                continue;
            }
            $definition = $model::definition();
            $key = $definition->key();
            $data = $model::all(['conditions' => [$key => array_keys($ids)]]);
            foreach ($data as $value) {
                $this->_store[$model][$value[$key]] = $value['cid'];
            }
        }
    }
}