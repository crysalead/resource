<?php
namespace Lead\Resource\Spec\Suite\Chaos\JsonApi;

use Lead\Resource\Chaos\JsonApi\Cid;

use Lead\Resource\Spec\Fixture\Fixtures;
use Lead\Resource\Spec\Fixture\Model\Gallery;
use Lead\Resource\Spec\Fixture\Model\Image;
use Lead\Resource\Spec\Fixture\Model\Tag;

$box = \Kahlan\box('resource.spec');

$connection = $box->get('source.database.sqlite');

describe("Cid", function() use ($connection) {

    beforeEach(function() use ($connection) {

        $this->fixture = 'spec/Fixture/Payload/';
        $this->fixtures = new Fixtures([
            'connection' => $connection,
            'fixtures'   => [
                'gallery'   => 'Lead\Resource\Spec\Fixture\Schema\Gallery',
                'image'     => 'Lead\Resource\Spec\Fixture\Schema\Image',
                'image_tag' => 'Lead\Resource\Spec\Fixture\Schema\ImageTag',
                'tag'       => 'Lead\Resource\Spec\Fixture\Schema\Tag'
            ]
        ]);

        $this->cid = new Cid();

    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    describe("->ingest()", function() {

        it("ingest data", function() {
            $collection = [[
                'title' => 'Amiga 1200',
                'gallery' => [
                    'name' => 'Gallery 1'
                ],
                'images_tags' => [
                    [
                        'tag_id' => 1
                    ],
                    [
                        'tag_id' => 2
                    ]
                ]
            ]];
            $this->cid->ingest($collection, Image::class);
            expect($this->cid->resolve($collection, Image::class))->toBe($collection);

        });

        it("ingest data with cid", function() {


            $this->fixtures->populate('gallery');
            $this->fixtures->populate('image');
            $this->fixtures->populate('image_tag');
            $this->fixtures->populate('tag');

            $collection = [[
                'title' => 'Amiga 1200',
                'gallery_cid' => 'G1',
                'images_tags' => [
                    [
                        'tag_cid' => 'T1'
                    ],
                    [
                        'tag_cid' => 'T2'
                    ]
                ]
            ]];
            $this->cid->ingest($collection, Image::class);
            expect($this->cid->resolve($collection, Image::class))->toBe([[
                'title' => 'Amiga 1200',
                'images_tags' => [
                    [
                        'tag_id' => 1
                    ],
                    [
                        'tag_id' => 2
                    ]
                ],
                'gallery_id' => 1
            ]]);

        });

    });

});