<?php
namespace Lead\Resource\Chaos\JsonApi;

use Lead\Resource\ResourceException;

class CidResolver
{
    /**
     * Indexes included data using type & id.
     *
     * @var array
     */
    protected $_store = [];

    public function resolve($collection, $model, &$validationErrors = [])
    {
        $this->_ingest($collection, $model);
        $this->_fetchData();
        $definition = $model::definition();
        $result = [];
        $relations = [];
        foreach ($definition->relations() as $name) {
            $relations[$name] = $definition->relation($name);
        }
        $replacer = function($model, $oldKey, $newKey, $i, &$data, &$validationErrors) {
            $cid = $data[$oldKey];
            $id = $this->_store[$model][$cid] ?? null;
            if ($cid && $id === null) {
                $name = basename(str_replace('\\', '/', $model));
                $validationErrors[$i] = $validationErrors[$i] ?? [$oldKey => ["No `{$name}` resource(s) found with value `{$cid}` as `cid`."]];
            }
            $data[$newKey] = $id;
            $definition = $model::definition();
            if (!$definition->has($oldKey)) {
                unset($data[$oldKey]);
            }
        };

        foreach ($collection as $i => $data) {
            if (!isset($data['id']) && isset($data['cid'])) {
                $cid = $data[ 'cid'];
                $id = $this->_store[$model][$cid];
                if ($id !== null) {
                    $data['id'] = $id;
                }
            }
            $validationErrors[$i] = $validationErrors[$i] ?? null;
            foreach ($relations as $key => $relation) {
                $to = $relation->to();
                if ($relation->type() === 'belongsTo') {
                    if (array_key_exists($key . 'Cid', $data)) {
                        $replacer($to, $key . 'Cid', $key . 'Id', $i, $data, $validationErrors);
                    } elseif (array_key_exists($key . '_cid', $data)) {
                        $replacer($to, $key . '_cid', $key . '_id', $i, $data, $validationErrors);
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
            if (!isset($data['id']) && isset($data['cid'])) {
                $this->_store[$model][$data['cid']] = null;
            }
            foreach ($relations as $key => $relation) {
                $to = $relation->to();
                if ($relation->type() === 'belongsTo') {
                    if (isset($data[$key . 'Cid'])) {
                        $this->_store[$to][$data[$key . 'Cid']] = null;
                    } elseif (isset($data[$key . '_cid'])) {
                        $this->_store[$to][$data[$key . '_cid']] = null;
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
        foreach ($this->_store as $model => $cids) {
            if (!$cids) {
                continue;
            }
            $definition = $model::definition();
            if (!$definition->has('cid')) {
                continue;
            }
            $data = $model::all(['conditions' => ['cid' => array_keys($cids)]]);
            $key = $definition->key();
            foreach ($data as $value) {
                $this->_store[$model][$value['cid']] = $value[$key];
            }
        }
    }
}