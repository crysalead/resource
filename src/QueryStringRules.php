<?php
namespace Lead\Resource;

class QueryStringRules
{
    protected $_controller = null;

    protected $_allowed = [];

    public function __construct($controller)
    {
        $this->_controller = $controller;
    }

    public function allow($action, $rule)
    {
        $this->_allowed[$action] = $rule;
        return $this;
    }

    public function allowed($action)
    {
        return $this->_allowed[$action] ?? [];
    }

    public function check($action, $queryParameters, $allowedExtra = [])
    {
        return $this->_check($action, $queryParameters, $this->allowed($action), [], $allowedExtra);
    }

    public function _check($action, $queryParameters, $allowed, $basePath, $allowedExtra)
    {
        $notAllowed = [];
        if ($allowed === true) {
            return $notAllowed;
        }
        foreach ($queryParameters as $key => $value) {
            if (is_array($value) && !$value) { // ignore empty arrays
                continue;
            }
            if (
                (!isset($allowed[$key]) && !in_array($key, $allowed, true)) &&
                (!isset($allowedExtra[$key]) && !in_array($key, $allowedExtra, true))
            ) {
                $notAllowed[] = join('.', array_merge($basePath, [$key]));
            } elseif (is_array($value) && array_keys($value) !== range(0, count($value) - 1)) { // only check if $value is non empty associative array
                $notAllowed = array_merge($notAllowed, $this->_check($action, $value, $allowed[$key] ?? [], array_merge($basePath, [$key]), $allowedExtra[$key] ?? []));
            }
        }
        return $notAllowed;
    }
}
