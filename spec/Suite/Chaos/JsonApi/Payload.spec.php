<?php
namespace Lead\Resource\Spec\Suite\Chaos\JsonApi;

use Lead\Resource\Chaos\JsonApi\Payload;

use Lead\Resource\Spec\Fixture\Fixtures;
use Lead\Resource\Spec\Fixture\Model\Gallery;
use Lead\Resource\Spec\Fixture\Model\Image;
use Lead\Resource\Spec\Fixture\Model\Tag;

$box = box('resource.spec');

$connection = $box->get('source.database.sqlite');

describe("Payload", function() use ($connection) {

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

    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    describe("->set()", function() {

        it("sets an error with trying to add a non Chaos entity", function() {

            $payload = new Payload();
            $payload->set(['hello' => 'world']);

            expect($payload->errors())->toBe([[
                'status' => 500,
                'code'   => 500,
                'title'  => "The JSON-API serializer only supports Chaos entities."
            ]]);

        });

        it("adds validation errors", function() {

            $validator = Gallery::validator();
            $validator->rule('name', 'not:empty');

            $gallery = Gallery::create();
            $gallery->validates();

            $payload = new Payload();
            $payload->set($gallery);

            expect($payload->errors())->toBe([[
                'status' => 422,
                'code'   => 0,
                'title'  => "Validation Error",
                'meta'   => [
                    [
                        'name' => [
                            'is required'
                        ]
                    ]
                ]
            ]]);

        });

    });

    describe("->delete()", function() {

        it("sets a delete payload", function() {

            $this->fixtures->populate('image');

            $payload = new Payload();
            $images = Image::all();
            $payload->delete($images);
            expect($payload->serialize())->toEqual([
                'data' => [
                    ['type' => 'Image', 'id' => 1],
                    ['type' => 'Image', 'id' => 2],
                    ['type' => 'Image', 'id' => 3],
                    ['type' => 'Image', 'id' => 4],
                    ['type' => 'Image', 'id' => 5]
                ]
            ]);

        });

    });

    describe("->export()", function() {

        it("exports payload as nested array", function() {

            $json = file_get_contents($this->fixture . 'collection.json');
            $payload = Payload::parse($json);
            expect($payload->export())->toBe([
                [
                    'id'     => '1',
                    'title'  => 'JSON API paints my bikeshed!',
                    'author' => [
                        'id'         => '9',
                        'firstName' => 'Dan',
                        'lastName'  => 'Gebhardt',
                        'twitter'    => 'dgeb'
                    ],
                    'comments' => [
                        [
                            'id'   => '5',
                            'body' => 'First!'
                        ],
                        [
                            'id'   => '12',
                            'body' => 'I like XML better'
                        ]
                    ]
                ],[
                    'id' => '2',
                    'title' => 'JSON API is awesome!',
                    'author' => [
                        'id' => '9',
                        'firstName' => 'Dan',
                        'lastName' => 'Gebhardt',
                        'twitter' => 'dgeb'
                    ],
                    'comments' => []
                ]
            ]);
            expect($payload->meta())->toBe([
                'count' => 13
            ]);
        });

    });

    describe("->serialize()", function() {

        it("serializes an empty payload", function() {

            $payload = new Payload();

            expect($payload->serialize())->toEqual([
                'data' => []
            ]);

        });

        it("serializes unexisting entities", function() {

            $image = Image::create([
                'title' => 'Amiga 1200'
            ]);
            $image->tags[] = ['name' => 'Computer'];
            $image->tags[] = ['name' => 'Science'];
            $image->gallery = ['name' => 'Gallery 1'];

            $payload = new Payload();
            $payload->set($image);
            expect($payload->data())->toBe([
                'type' => 'Image',
                'attributes' => [
                    'title' => 'Amiga 1200',
                    'gallery' => [
                        'name' => 'Gallery 1'
                    ],
                    'tags' => [
                        ['name' => 'Computer'],
                        ['name' => 'Science']
                    ]
                ]
            ]);

            expect($payload->included())->toBe([]);

        });

        it("serializes existing entities", function() {

            $this->fixtures->populate('gallery');
            $this->fixtures->populate('image');
            $this->fixtures->populate('image_tag');
            $this->fixtures->populate('tag');

            $image = Image::load(1, ['embed' => ['gallery', 'tags']]);

            $payload = new Payload();
            $payload->set($image);

            expect($payload->isCollection())->toBe(false);

            expect($payload->data())->toBe([
                'type' => 'Image',
                'id' => 1,
                'attributes' => [
                    'gallery_id' => 1,
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200',
                ],
                'relationships' => [
                    'gallery' => [
                        'data' => [
                            'type' => 'Gallery',
                            'id' => 1
                        ]
                    ],
                    'tags' => [
                        'data' => [
                            [
                                'type' => 'Tag',
                                'id' => 1
                            ],
                            [
                                'type' => 'Tag',
                                'id' => 3
                            ]
                        ]
                    ]
                ]
            ]);

            expect($payload->included())->toBe([
                [
                    'type' => 'Gallery',
                    'id' => 1,
                    'attributes' => [
                        'name' => 'Foo Gallery'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 1,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 1
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 2,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 3
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 1,
                    'attributes' => [
                        'name' => 'High Tech'
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 3,
                    'attributes' => [
                        'name' => 'Computer'
                    ]
                ]
            ]);

        });

        it("serializes collections", function() {

            $this->fixtures->populate('image');

            $images = Image::find()->where(['id' => [1 , 2]])->all();
            $images->meta(['count' => 10]);
            $payload = new Payload();
            $payload->set($images);

            expect($payload->isCollection())->toBe(true);

            expect($payload->data())->toBe([
                [
                    'type' => 'Image',
                    'id' => 1,
                    'attributes' => [
                        'gallery_id' => 1,
                        'name' => 'amiga_1200.jpg',
                        'title' => 'Amiga 1200'
                    ]
                ],
                [
                    'type' => 'Image',
                    'id' => 2,
                    'attributes' => [
                        'gallery_id' => 1,
                        'name' => 'srinivasa_ramanujan.jpg',
                        'title' => 'Srinivasa Ramanujan'
                    ]
                ]
            ]);

            expect($payload->included())->toBe([]);

            expect($payload->meta())->toBe(['count' => 10]);

        });

        it("serializes parsed JSON-API payload", function() {

            $json = file_get_contents($this->fixture . 'collection.json');
            $payload = Payload::parse($json);
            expect($payload->serialize())->toEqual(json_decode($json, true));

            $json = file_get_contents($this->fixture . 'item.json');
            $payload = Payload::parse($json);
            expect($payload->serialize())->toEqual(json_decode($json, true));
        });

    });

    describe("::parse()", function() {

        it("parses JSON-API payload", function() {

            $json = file_get_contents($this->fixture . 'collection.json');

            $payload = Payload::parse($json);

            expect($payload->jsonapi())->toBe([
                'version' => '1.0'
            ]);

            expect($payload->meta())->toBe([
                'count' => 13
            ]);

            expect($payload->links())->toBe([
                'self' => 'http://example.com/articles',
                'next' => 'http://example.com/articles?page[offset]=2',
                'last' => 'http://example.com/articles?page[offset]=10'
            ]);

            expect($payload->included())->toBe([
                [
                    'type' => 'people',
                    'id'   => '9',
                    'attributes' => [
                        'firstName' => 'Dan',
                        'lastName'  => 'Gebhardt',
                        'twitter'    => 'dgeb'
                    ],
                    'links' => [
                        'self' => 'http://example.com/people/9'
                    ]
                ],
                [
                    'type' => 'comments',
                    'id' => '5',
                    'attributes' => [
                        'body' => 'First!'
                    ],
                    'relationships' => [
                        'author' => [
                            'data' => [
                                'type' => 'people',
                                'id'   => '2'
                            ]
                        ]
                    ],
                    'links' => [
                        'self' => 'http://example.com/comments/5'
                    ]
                ],
                [
                    'type' => 'comments',
                    'id' => '12',
                    'attributes' => [
                        'body' => 'I like XML better'
                    ],
                    'relationships' => [
                        'author' => [
                            'data' => [
                                'type' => 'people',
                                'id'   => '9'
                            ]
                        ]
                    ],
                    'links' => [
                        'self' => 'http://example.com/comments/12'
                    ]
                ]
            ]);

        });

        it("parses JSON-API errors payload", function() {

            $json = file_get_contents($this->fixture . 'errors.json');
            $payload = Payload::parse($json);

            expect($payload->errors())->toBe([
                [
                    'code' => '123',
                    'source' => [
                        'pointer' => '/data/attributes/firstName'
                    ],
                    'title' => 'Value is too short',
                    'detail' => 'First name must contain at least three characters.'
                ]
            ]);

        });

        it("parses decoded JSON-API payload", function() {

            $payload = Payload::parse([
                'data' => [[
                    'id' => 1,
                    'attributes' => ['name' => 'value']
                ]]
            ]);

            expect($payload->export())->toBe([[
                'id' => 1,
                'name' => 'value'
            ]]);

        });


    });

});