<?php
namespace Lead\Resource;

use Lead\Resource\Chaos\JsonApi\CidResolver;

class RequestRules
{
    protected $_operators = [];

    protected $_controller = null;

    protected $_allowedFields = [];

    protected $_restrictedValues = [];

    public function __construct($controller)
    {
        $this->_controller = $controller;
        $this->_operators = [
            ':eq' => '=',
            ':ne' => '!=',
            ':lte' => '<=',
            ':lt' => '<',
            ':gte' => '>=',
            ':gt' => '>'
        ];
    }

    public function operators($operators = null)
    {
        if (!func_num_args()) {
            return $this->_operators;
        }
        $this->_operators = $operators;
        return $this;
    }

    public function parseFilter($filter, $model)
    {
        $definition = $model::definition();
        if (isset($filter) && is_array($filter)) {
            foreach ($filter as $key => $value) {
                if (is_array($value)) {
                    throw new ResourceException("Invalid filter format in the query string.");
                }
                if ($definition->has($key)) {
                    $column = $definition->column($key);
                    if ($column['type'] === 'boolean') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    } elseif ($column['type'] !== 'string') {
                        $value = $value === 'null' ? null : $value;
                    }
                }
                $filter[$key] = strpos($value, ',') !== false ? array_map('trim', explode(',', $value)) : $value;
            }
        }
        $resolver = new CidResolver();
        $collection = [$filter];
        $collection = $resolver->resolve($collection, $model, $validationErrors);
        return $collection[0];
    }

    public function parseConditions($conditions)
    {
        $operators = $this->operators();
        $result = [];
        foreach ($conditions as $key => $value) {
            if (is_string($key) && $key[0] === ':') {
                $key = isset($operators[$key]) ? $operators[$key] : $key;
            }
            if (!is_array($value)) {
                $result[$key] = strpos($value, ',') !== false ? array_map('trim', explode(',', $value)) : $value;
            } else {
                $result[$key] = $this->parseConditions($value);
            }
        }
        return $result;
    }

    public function allowFields($action, $rule)
    {
        $actions = is_array($action) ? $action : [$action];

        $merge = function(&$source, $rule) use (&$merge) {
            foreach ($rule as $key => $value) {
                if ($value === true) {
                    $source[$key] = true;
                } elseif (is_array($value)) {
                    $source[$key] = $source[$key] ?? [];
                    $merge($source[$key], $value);
                } elseif (!in_array($value, $source, true)) {
                    $source[] = $value;
                }
            }
        };

        foreach ($actions as $action) {
            $this->_allowedFields[$action] = $this->_allowedFields[$action] ?? [];
            $merge($this->_allowedFields[$action], $rule);
        }
        return $this;
    }

    public function restrictValues($format, $action, $rule)
    {
        $actions = is_array($action) ? $action : [$action];
        foreach ($actions as $action) {
            $this->_restrictedValues[$format][$action] = $rule;
        }
        return $this;
    }

    public function allowedFields($action)
    {
        return $this->_allowedFields[$action] ?? [];
    }

    public function restrictedValues($format, $action)
    {
        return $this->_restrictedValues[$format][$action] ?? ($this->_restrictedValues['*'][$action] ?? []);
    }

    public function check($format, $action, $queryArgs, $allowedFieldsExtra = [])
    {
        $checkResult = $this->_check('*', $queryArgs, $this->allowedFields('*'), $this->restrictedValues($format, $action), [], $allowedFieldsExtra);
        if ($checkResult['fields']) {
            $notAllowed = $this->_check($action, $queryArgs, $this->allowedFields($action), $this->restrictedValues($format, $action), [], $allowedFieldsExtra);
            if ($notAllowed['fields']) {
                $name = $this->_controller->name();
                throw new ResourceException("Resource `{$name}:{$action}` does not allow the following filter(s) `[" . join(',', $notAllowed['fields']) . "]`.", 422);
            }
        }

        $checkResult = $this->_check('*', $queryArgs, $this->allowedFields($action), $this->restrictedValues($format, '*'), [], $allowedFieldsExtra = []);

        if ($checkResult['values']) {
            $notAllowed = $this->_check($action, $queryArgs, $this->allowedFields($action), $this->restrictedValues($format, $action), [], $allowedFieldsExtra);
            if ($notAllowed['values']) {
                $name = $this->_controller->name();
                foreach ($notAllowed['values'] as $key => $values) {
                    throw new ResourceException("Resource `{$name}:{$action}` does not allow the following {$key} `[" . join(',', $values) . "]`.", 422);
                }
            }
        }
    }

    protected function _check($action, $queryArgs, $allowedFields, $restrictedValues, $basePath, $allowedFieldsExtra)
    {
        $checkResult = ['fields' => [], 'values' => [], 'allowed' => []];

        foreach ($queryArgs as $key => $value) {
            if (is_array($value) && !$value) { // ignore empty arrays
                continue;
            }
            if (is_string($key) && !preg_match("/^[A-Za-z]/", $key) || is_numeric($key)) { // is operator
                if (is_array($value)) {
                    $result = $this->_check($action, $value, $allowedFields, $restrictedValues, $basePath, $allowedFieldsExtra);
                    $checkResult['fields'] = array_merge($checkResult['fields'], $result['fields']);
                    $checkResult['values'] = array_merge($checkResult['values'], $result['values']);
                }
                continue;
            }

            $permittedValues = $restrictedValues[$key] ?? null;
            $fieldName = join('.', array_merge($basePath, [$key]));

            if (
                $allowedFields !== true &&
                (!isset($allowedFields[$key]) && !in_array($key, $allowedFields, true)) &&
                (!isset($allowedFieldsExtra[$key]) && !in_array($key, $allowedFieldsExtra, true))
            ) {
                $checkResult['fields'][] = $fieldName;
            } elseif (is_array($value)) {
                if (isset($permittedValues) && count($value) === count($value, true) && array_keys($value) === range(0, count($value) - 1)) {
                    if ($diff = array_diff($value, $permittedValues)) {
                        $checkResult['values'][$fieldName] = $diff;
                    }
                }
                if ($allowedFields !== true) {
                    $result = $this->_check($action, $value, $allowedFields[$key] ?? [], $restrictedValues[$key] ?? [], array_merge($basePath, [$key]), $allowedFieldsExtra[$key] ?? []);
                    $checkResult['fields'] = array_merge($checkResult['fields'], $result['fields']);
                    $checkResult['values'] = array_merge($checkResult['values'], $result['values']);
                }
            } else {
                if (isset($permittedValues) && $diff = array_diff([$value], $permittedValues)) {
                    $checkResult['values'][$fieldName] = $diff;
                }
            }
        }
        return $checkResult;
    }
}
