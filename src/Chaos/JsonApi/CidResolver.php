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

    public function resolve($collection, $model)
    {
        $this->_ingest($collection, $model);
        $this->_fetchData();
        $definition = $model::definition();
        $result = [];
        $relations = [];
        foreach ($definition->relations() as $name) {
            $relations[$name] = $definition->relation($name);
        }
        foreach ($collection as $data) {
            foreach ($relations as $key => $relation) {
                $to = $relation->to();
                if ($relation->type() === 'belongsTo') {
                    if (!empty($data[$key . 'Cid'])) {
                        $data[$key . 'Id'] = $this->_store[$to][$data[$key . 'Cid']];
                        unset($data[$key . 'Cid']);
                    } elseif (!empty($data[$key . '_cid'])) {
                        $data[$key . '_id'] = $this->_store[$to][$data[$key . '_cid']];
                        unset($data[$key . '_cid']);
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
                    if (!empty($data[$key . 'Cid'])) {
                        $this->_store[$to][$data[$key . 'Cid']] = null;
                    } elseif (!empty($data[$key . '_cid'])) {
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
            foreach ($this->_store[$model] as $cid => $id) {
                if ($id === null) {
                    $name = basename(str_replace('\\', '/', $model));
                    throw new ResourceException("No `{$name}` resource(s) found with value `{$cid}` as `cid`.", 404);
                }
            }
        }
    }
}