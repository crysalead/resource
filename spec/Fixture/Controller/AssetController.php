<?php
namespace Lead\Resource\Spec\Fixture\Controller;

use Lead\Resource\Chaos\JsonApi\JsonApiHandlers;

class AssetController extends \Lead\Resource\Controller
{
    use JsonApiHandlers;

    protected function _inputs() {
        return [
            ['json', 'json-api', 'csv']
        ];
    }

    protected function _outputs() {
        return [
            ['json', 'json-api', 'csv'],
            'view'  => ['json', 'json-api', 'csv'],
            'index' => ['json', 'json-api', 'csv']
        ];
    }

    public function binding()
    {
        return 'Lead\Resource\Spec\Fixture\Model\Image';
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
}
