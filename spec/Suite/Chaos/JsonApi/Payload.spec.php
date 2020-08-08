<?php
namespace Lead\Resource\Spec\Suite\Chaos\JsonApi;

use Lead\Resource\Chaos\JsonApi\Payload;

use Lead\Resource\Spec\Fixture\Fixtures;
use Lead\Resource\Spec\Fixture\Model\Gallery;
use Lead\Resource\Spec\Fixture\Model\Image;
use Lead\Resource\Spec\Fixture\Model\Tag;

$box = \Kahlan\box('resource.spec');

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

        $this->payload = new Payload();

    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    describe("->set()", function() {

        it("sets an error with trying to add a non Chaos entity", function() {

            $this->payload->set(['hello' => 'world']);

            expect($this->payload->errors())->toBe([[
                'status' => '500',
                'code'   => 500,
                'title'  => "The JSON-API serializer only supports Chaos entities."
            ]]);

        });

        it("adds validation errors", function() {

            $validator = Gallery::validator();
            $validator->rule('name', 'not:empty');

            $gallery = Gallery::create();
            $gallery->validates();

            $this->payload->set($gallery, ['embed' => true]);

            expect($this->payload->errors())->toBe([[
                'status' => '422',
                'code'   => 422,
                'title'  => "Validation Error",
                'data'   => [
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

            $images = Image::all();
            $this->payload->delete($images);
            expect($this->payload->serialize())->toEqual([
                'data' => [
                    ['type' => 'Image', 'id' => 1, 'exists' => true],
                    ['type' => 'Image', 'id' => 2, 'exists' => true],
                    ['type' => 'Image', 'id' => 3, 'exists' => true],
                    ['type' => 'Image', 'id' => 4, 'exists' => true],
                    ['type' => 'Image', 'id' => 5, 'exists' => true]
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
                            'body' => 'I like XML better',
                            'author' => [
                                'id' => '9',
                                'firstName' => 'Dan',
                                'lastName' => 'Gebhardt',
                                'twitter' => 'dgeb'
                            ]
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

        it("export unexisting & existing entities", function() {

            $image = Image::create([
                'title' => 'Amiga 1200'
            ]);
            $image->tags[] = Tag::create(['id' => 1, 'name' => 'Computer'], ['exists' => true]);
            $image->tags[] = Tag::create(['id' => 2, 'name' => 'Science'], ['exists' => true]);
            $image->gallery = ['name' => 'Gallery 1'];

            $this->payload->set($image, ['embed' => true]);

            $collection = $this->payload->export(null, Image::class);
            $item = $collection[0];

            expect($item->data())->toEqual([
                'gallery_id' => null,
                'title' => 'Amiga 1200',
                'gallery' => [
                    'name' => 'Gallery 1'
                ],
                'images_tags' => [
                    [
                        'tag_id' => 1,
                        'tag' => [
                            'id' => 1,
                            'name' => 'Computer'
                        ]
                    ],
                    [
                        'tag_id' => 2,
                        'tag' => [
                            'id' => 2,
                            'name' => 'Science'
                        ]
                    ]
                ],
                'tags' => [
                    [
                        'id' => 1,
                        'name' => 'Computer'
                    ],
                    [
                        'id' => 2,
                        'name' => 'Science'
                    ]
                ]
            ]);

            expect($item->exists())->toBe(false);
            expect($item->gallery->exists())->toBe(false);
            expect($item->images_tags[0]->exists())->toBe(false);
            expect($item->images_tags[1]->exists())->toBe(false);
            expect($item->tags[0]->exists())->toBe(true);
            expect($item->tags[1]->exists())->toBe(true);

        });

    });

    describe("->serialize()", function() {

        it("serializes an empty payload", function() {

            expect($this->payload->serialize())->toEqual([
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

            $this->payload->set($image, ['embed' => true]);
            expect($this->payload->data())->toEqual([
                'type'   => 'Image',
                'exists' => false,
                'attributes' => [
                    'title' => 'Amiga 1200',
                    'gallery_id' => null
                ],
                'relationships' => [
                    'gallery' => [
                        'data' => [
                            'type' => 'Gallery',
                            'exists' => false,
                            'attributes' => [
                                'name' => 'Gallery 1'
                            ]
                        ]
                    ],
                    'images_tags' => [
                        'data' => [
                            [
                                'type' => 'ImageTag',
                                'exists' => false,
                                'attributes' => [
                                    'tag_id' => null
                                ],
                                'relationships' => [
                                    'tag' => [
                                        'data' => [
                                            'type' => 'Tag',
                                            'exists' => false,
                                            'attributes' => [
                                                'name' =>'Computer'
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            [
                                'type' => 'ImageTag',
                                'exists' => false,
                                'attributes' => [
                                    'tag_id' => null
                                ],
                                'relationships' => [
                                    'tag' => [
                                        'data' => [
                                            'type' => 'Tag',
                                            'exists' => false,
                                            'attributes' => [
                                                'name' =>'Science'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->included())->toBe([]);

            expect($this->payload->embedded())->toBe(['gallery', 'images_tags.tag']);

        });

        it("serializes unexisting & existing entities", function() {

            $image = Image::create([
                'title' => 'Amiga 1200'
            ]);
            $image->tags[] = Tag::create(['id' => 1, 'name' => 'Computer'], ['exists' => true]);
            $image->tags[] = Tag::create(['id' => 2, 'name' => 'Science'], ['exists' => true]);
            $image->gallery = ['name' => 'Gallery 1'];

            $this->payload->set($image, ['embed' => true]);
            expect($this->payload->data())->toEqual([
                'type'   => 'Image',
                'exists' => false,
                'attributes' => [
                    'title' => 'Amiga 1200',
                    'gallery_id' => null
                ],
                'relationships' => [
                    'gallery' => [
                        'data' => [
                            'type' => 'Gallery',
                            'exists' => false,
                            'attributes' => [
                                'name' => 'Gallery 1'
                            ]
                        ]
                    ],
                    'images_tags' => [
                        'data' => [
                            [
                                'type' => 'ImageTag',
                                'exists' => false,
                                'attributes' => [
                                    'tag_id' => 1
                                ],
                                'relationships' => [
                                    'tag' => [
                                        'data' => [
                                            'type' => 'Tag',
                                            'id' => 1,
                                            'exists' => true
                                        ]
                                    ]
                                ]
                            ],
                            [
                                'type' => 'ImageTag',
                                'exists' => false,
                                'attributes' => [
                                    'tag_id' => 2
                                ],
                                'relationships' => [
                                    'tag' => [
                                        'data' => [
                                            'type' => 'Tag',
                                            'id' => 2,
                                            'exists' => true
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->included())->toBe([
                [
                    'type' => 'Tag',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'name' => 'Computer'
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 2,
                    'exists' => true,
                    'attributes' => [
                        'name' => 'Science'
                    ]
                ]
            ]);

            expect($this->payload->embedded())->toBe(['gallery', 'images_tags.tag']);

        });

        it("serializes existing entities", function() {

            $this->fixtures->populate('gallery');
            $this->fixtures->populate('image');
            $this->fixtures->populate('image_tag');
            $this->fixtures->populate('tag');

            $image = Image::load(1, ['embed' => ['gallery', 'tags']]);

            $this->payload->set($image, ['embed' => true]);

            expect($this->payload->isCollection())->toBe(false);

            expect($this->payload->data())->toBe([
                'type' => 'Image',
                'id' => 1,
                'exists' => true,
                'attributes' => [
                    'cid' => 'I1',
                    'gallery_id' => 1,
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200',
                ],
                'relationships' => [
                    'gallery' => [
                        'data' => [
                            'type' => 'Gallery',
                            'id' => 1,
                            'exists' => true
                        ]
                    ],
                    'images_tags' => [
                        'data' => [
                            [
                                'type' => 'ImageTag',
                                'id' => 1,
                                'exists' => true
                            ],
                            [
                                'type' => 'ImageTag',
                                'id' => 2,
                                'exists' => true
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->included())->toBe([
                [
                    'type' => 'Gallery',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'G1',
                        'name' => 'Foo Gallery'
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'T1',
                        'name' => 'High Tech'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 1
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 1,
                                'exists' => true
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 3,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'T3',
                        'name' => 'Computer'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 2,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 3
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 3,
                                'exists' => true
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->embedded())->toBe(['gallery', 'images_tags.tag']);

        });

        it("doesn't duplicate included data", function() {

            $this->fixtures->populate('gallery');
            $this->fixtures->populate('image');
            $this->fixtures->populate('image_tag');
            $this->fixtures->populate('tag');

            $image1 = Image::load(1, ['embed' => ['gallery', 'tags']]);
            $image4 = Image::load(4, ['embed' => ['gallery', 'tags']]);

            $this->payload->set(Image::create([$image1, $image4], ['type' => 'set']), ['embed' => true]);

            expect($this->payload->isCollection())->toBe(true);

            expect($this->payload->data())->toBe([
                [
                    'type' => 'Image',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'I1',
                        'gallery_id' => 1,
                        'name' => 'amiga_1200.jpg',
                        'title' => 'Amiga 1200',
                    ],
                    'relationships' => [
                        'gallery' => [
                            'data' => [
                                'type' => 'Gallery',
                                'id' => 1,
                                'exists' => true
                            ]
                        ],
                        'images_tags' => [
                            'data' => [
                                [
                                    'type' => 'ImageTag',
                                    'id' => 1,
                                    'exists' => true
                                ],
                                [
                                    'type' => 'ImageTag',
                                    'id' => 2,
                                    'exists' => true
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'Image',
                    'id' => 4,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'I4',
                        'gallery_id' => 2,
                        'name' => 'silicon_valley.jpg',
                        'title' => 'Silicon Valley',
                    ],
                    'relationships' => [
                        'gallery' => [
                            'data' => [
                                'type' => 'Gallery',
                                'id' => 2,
                                'exists' => true
                            ]
                        ],
                        'images_tags' => [
                            'data' => [
                                [
                                    'type' => 'ImageTag',
                                    'id' => 5,
                                    'exists' => true
                                ],
                                [
                                    'type' => 'ImageTag',
                                    'id' => 6,
                                    'exists' => true
                                ],
                                [
                                    'type' => 'ImageTag',
                                    'id' => 7,
                                    'exists' => true
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->included())->toBe([
                [
                    'type' => 'Gallery',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'G1',
                        'name' => 'Foo Gallery'
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'T1',
                        'name' => 'High Tech'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 1
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 1,
                                'exists' => true
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 3,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'T3',
                        'name' => 'Computer'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 2,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 1,
                        'tag_id' => 3
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 3,
                                'exists' => true
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'Gallery',
                    'id' => 2,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'G2',
                        'name' => 'Bar Gallery'
                    ]
                ],
                [
                    'type' => 'Tag',
                    'id' => 6,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'T6',
                        'name' => 'City'
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 5,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 4,
                        'tag_id' => 6
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 6,
                                'exists' => true
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 6,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 4,
                        'tag_id' => 3
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 3,
                                'exists' => true
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'ImageTag',
                    'id' => 7,
                    'exists' => true,
                    'attributes' => [
                        'image_id' => 4,
                        'tag_id' => 1
                    ],
                    'relationships' => [
                        'tag' => [
                            'data' => [
                                'type' => 'Tag',
                                'id' => 1,
                                'exists' => true
                            ]
                        ]
                    ]
                ]
            ]);

            expect($this->payload->embedded())->toBe(['gallery', 'images_tags.tag']);

        });

        it("doesn't embed any data by default", function() {

            $this->fixtures->populate('gallery');
            $this->fixtures->populate('image');
            $this->fixtures->populate('image_tag');
            $this->fixtures->populate('tag');

            $image = Image::load(1, ['embed' => ['gallery', 'tags']]);

            $this->payload->set($image);

            expect($this->payload->isCollection())->toBe(false);

            expect($this->payload->data())->toBe([
                'type' => 'Image',
                'id' => 1,
                'exists' => true,
                'attributes' => [
                    'cid' => 'I1',
                    'gallery_id' => 1,
                    'name' => 'amiga_1200.jpg',
                    'title' => 'Amiga 1200',
                ]
            ]);

            expect($this->payload->included())->toBe([]);

        });

        it("serializes collections", function() {

            $this->fixtures->populate('image');

            $images = Image::find()->where(['id' => [1 , 2]])->all();
            $images->meta(['count' => 10]);
            $this->payload->set($images, ['embed' => true]);

            expect($this->payload->isCollection())->toBe(true);

            expect($this->payload->data())->toBe([
                [
                    'type' => 'Image',
                    'id' => 1,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'I1',
                        'gallery_id' => 1,
                        'name' => 'amiga_1200.jpg',
                        'title' => 'Amiga 1200'
                    ]
                ],
                [
                    'type' => 'Image',
                    'id' => 2,
                    'exists' => true,
                    'attributes' => [
                        'cid' => 'I2',
                        'gallery_id' => 1,
                        'name' => 'srinivasa_ramanujan.jpg',
                        'title' => 'Srinivasa Ramanujan'
                    ]
                ]
            ]);

            expect($this->payload->included())->toBe([]);

            expect($this->payload->meta())->toBe(['count' => 10]);

        });

        it("serializes parsed JSON-API payload", function() {

            $json = file_get_contents($this->fixture . 'collection.json');
            $payload = Payload::parse($json);
            expect($payload->serialize())->toEqual(json_decode($json, true));

            $json = file_get_contents($this->fixture . 'item.json');
            $payload = Payload::parse($json);
            expect($payload->serialize())->toEqual(json_decode($json, true));
        });

        it("doesn't filter out `null` values", function() {

            $image = Image::create([
                'id' => 1,
                'gallery_id' => 0,
                'name' => null,
                'title' => ''
            ], ['exists' => true]);

            $this->payload->set($image, ['embed' => true]);

            expect($this->payload->data())->toBe([
                'type' => 'Image',
                'id' => 1,
                'exists' => true,
                'attributes' => [
                    'gallery_id' => 0,
                    'name' => null,
                    'title' => ''
                ]
            ]);

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

            expect($payload->embedded())->toBe(['author', 'comments.author']);

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