<?php
namespace Lead\Resource\Spec\Fixture\Controller;

use Lead\Resource\Chaos\JsonApi\JsonApiHandlers;

class Controller extends \Lead\Resource\Controller
{
    use JsonApiHandlers;

    public function binding()
    {
        return 'Lead\Resource\Spec\Fixture\Model\\' . $this->name();
    }

    public function index($query, $args)
    {
    }

    public function view($resource, $args)
    {
        return $resource;
    }

    public function edit($resource, $data, $args, $payload)
    {
        $resource->set($data->data());
        return $resource->save();
    }

    public function delete($resource)
    {
        return $resource->delete();
    }

    public function _render($data, $options = []) {
        $this->response = $options;
    }
}
