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

    public function index($resources) {
        return $resources;
    }

    public function view($resource) {
        return $resource;
    }

    public function add($resource) {
        return ($request->data) ? $resource->save() : $resource;
    }

    public function edit($resource) {
        return ($request->data) ? $resource->save($request->data) : $resource;
    }

    public function delete($resource) {
        return $resource->delete();
    }

    public function _render($data, $options = []) {
        $this->response = $options;
    }
}
