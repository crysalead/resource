<?php
namespace Lead\Resource\Spec\Suite\Chaos;

use Lead\Net\Http\Request;
use Lead\Net\Http\Response;
use Lead\Router\Router;
use Lead\Resource\Router\ResourceStrategy;

use Lead\Resource\Spec\Fixture\Fixtures;
use Lead\Resource\Spec\Fixture\Model\Gallery;
use Lead\Resource\Spec\Fixture\Model\GalleryDetail;
use Lead\Resource\Spec\Fixture\Model\Image;
use Lead\Resource\Spec\Fixture\Model\ImageTag;
use Lead\Resource\Spec\Fixture\Model\Tag;

$box = box('resource.spec');

$connection = $box->get('source.database.sqlite');

describe("Resource", function() use ($connection) {

    beforeEach(function() use ($connection) {

        $this->response = new Response();
        $this->router = new Router();
        $this->router->strategy('resource', new ResourceStrategy());

        $this->router->group(['namespace' => 'Lead\Resource\Spec\Fixture\Controller'], function($r) {
            $r->resource('Gallery');
            $r->resource('GalleryDetail');
            $r->resource('Image');
            $r->resource('ImageTag');
            $r->resource('Tag');
        });

        $this->connection = $connection;
        $this->fixtures = new Fixtures([
            'connection' => $connection,
            'fixtures'   => [
                'gallery'        => 'Lead\Resource\Spec\Fixture\Schema\Gallery',
                'gallery_detail' => 'Lead\Resource\Spec\Fixture\Schema\GalleryDetail',
                'image'          => 'Lead\Resource\Spec\Fixture\Schema\Image',
                'image_tag'      => 'Lead\Resource\Spec\Fixture\Schema\ImageTag',
                'tag'            => 'Lead\Resource\Spec\Fixture\Schema\Tag'
            ]
        ]);

    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    it("loads a resource", function() {

        $this->fixtures->populate('gallery');

        $r = $this->router;
        $route = $r->route('gallery/1', 'GET');

        $route->dispatch($this->response);
        expect($route->params)->toBe([
            'relations' => [],
            'resource'  => 'gallery',
            'id'        => '1',
            'action'    => null
        ]);

        $data = $route->dispatched->data();

        $expected = Gallery::load(1)->first()->data();

        expect($data['gallery']->data())->toBe($expected);
    });

    it("loads some related resource", function() {

        $this->fixtures->populate('gallery');
        $this->fixtures->populate('image');

        $r = $this->router;
        $route = $r->route('gallery/1/image', 'GET');

        $route->dispatch($this->response);
        expect($route->params)->toBe([
            'relations' => [['gallery', '1']],
            'resource'  => 'image',
            'id'        => null,
            'action'    => null
        ]);

        $data = $route->dispatched->data();

        $expected = Image::find([
            'conditions' => [
                'gallery_id' => 1
            ]
        ])->all()->data();

        expect($data['image']->data())->toBe($expected);

    });

});