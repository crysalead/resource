<?php
namespace Lead\Resource\Spec\Mock;

use Exception;

class RoutingTestController
{
    public $request = null;

    public $response = null;

    public function __invoke($request = null, $response = null)
    {
        $this->request = $request;
        $this->response = $response;
        return $this;
    }
}