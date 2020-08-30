<?php
namespace Lead\Resource\Spec\Suite\Chaos;

use InvalidArgumentException;
use Lead\Resource\ResourceException;

use Lead\Inflector\Inflector;
use Lead\Net\Http\Cgi\Request;
use Lead\Net\Http\Response;
use Lead\Net\Http\Media;
use Lead\Router\Router;
use Lead\Resource\Chaos\JsonApi\Payload;
use Lead\Resource\Router\ResourceStrategy;

use Chaos\ORM\Model;
use Chaos\ORM\Collection\Collection;
use Chaos\ORM\Collection\Through;

use Lead\Resource\Spec\Fixture\Fixtures;
use Lead\Resource\Spec\Fixture\Model\Gallery;
use Lead\Resource\Spec\Fixture\Model\GalleryDetail;
use Lead\Resource\Spec\Fixture\Model\Image;
use Lead\Resource\Spec\Fixture\Model\ImageTag;
use Lead\Resource\Spec\Fixture\Model\Tag;

$box = \Kahlan\box('resource.spec');

$connection = $box->get('source.database.sqlite');

describe("Controller", function() use ($connection) {

    beforeAll(function() {

        $this->router = new Router();
        $this->router->strategy('resource', new ResourceStrategy());

        $this->router->group(['namespace' => 'Lead\Resource\Spec\Fixture\Controller'], function($r) {
            $r->resource('Image');
            $r->resource('Asset');
        });

        $router = $this->router;

        Media::set('html', ['text/html', 'application/xhtml+xml', '*/*'], [
            'encode' => function($data, $options) {
                $defaults = [
                    'data'     => [],
                    'errors'   => []
                ];
                $options += $defaults;

                if (!empty($options['errors'])) {
                    $output = print_r($options['errors'], true);
                } else {
                    $output = print_r($options['data'], true);
                }

                return "<html>{$output}</html>";
            }
        ]);

        Media::set('form', ['application/x-www-form-urlencoded'], [
            'encode' => function($data, $options = []) {
                return $data;
            },
            'decode' => function($data) {
                return $data;
            }
        ]);

        Media::set('multipart-form', ['multipart/form-data'], [
            'encode' => function($data, $options = []) {
                return $data;
            },
            'decode' => function($data) {
                return $data;
            }
        ]);

        Media::set('json', ['application/json'], [
            'encode' => function($data, $options = [], $response = null) {
                $defaults = [
                    'depth' => 512,
                    'flag'  => 0
                ];
                $options += $defaults;
                if (!empty($options['errors'])) {
                    $e = reset($options['errors']);
                    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 499;
                    $data = [
                        'error' => [
                            'code'  => $errorCode,
                            'title' => $e->getMessage(),
                            'file'  => $e->getFile() . "({$e->getLine()})",
                            'trace' => explode("\n", $e->getTraceAsString())
                        ]
                    ];
                    if ($response) {
                        $response->status($errorCode);
                    }
                }
                $result = json_encode($data, $options['flag'], $options['depth']);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException(json_last_error_msg());
                }
                return $result;
            },
            'decode' => function($data, $options = []) {
                $defaults = [
                    'array' => true,
                    'depth' => 512,
                    'flag'  => 0
                ];
                $options += $defaults;
                if (!$data) {
                    return;
                }
                $result = json_decode($data, $options['array'], $options['depth'], $options['flag']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException(json_last_error_msg());
                }
                return $result;
            }
        ]);

        Media::set('json-api', ['application/vnd.api+json'], [
            'cast'   => false,
            'encode' => function($resource, $options, $response) use ($router) {
                $serializeError = function($e) {
                    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 499;
                    return [
                        'status' => (string) $errorCode,
                        'code'   => (int) $errorCode,
                        'title'  => $e->getMessage(),
                        'file'   => $e->getFile() . "({$e->getLine()})",
                        'trace'  => explode("\n", $e->getTraceAsString())
                    ];
                };
                try {
                    $payload = new Payload([
                        'link' => [$router, 'link'],
                        'exporter' => function($entity) {
                            return $entity->to('array', ['embed' => false]);
                        },
                        'type' => empty($options['bulk']) ? 'entity' : 'set'
                    ]);

                    if (is_array($resource)) {
                        // Hack to support raw data fetching, doesn't support the include parameter.
                        if (!empty($options['model'])) {
                            $model = $options['model'];
                            $schema = $model::definition();
                            $type = Inflector::camelize($schema->source());
                            $key = $schema->key();

                            $data = [];
                            foreach ($resource as $value) {
                                $id = $value[$key] ?? null;
                                unset($value[$key]);
                                foreach ($value as $k => $v) {
                                    // Don't forget to filter extername relations
                                    if ($schema->hasRelation($k, false)) {
                                        unset($value[$k]);
                                    }
                                }
                                $data[] = [
                                    'type' => $type,
                                    'id' => $id,
                                    'exists' => true,
                                    'attributes' => $value
                                ];
                            }
                            $resource = $data;
                        }
                        $payload->data($resource);
                        if (!empty($options['meta'])) {
                            $payload->meta($options['meta']);
                        }
                    } elseif ($resource instanceof Model || $resource instanceof Collection || $resource instanceof Through) {
                        $payload->set($resource, ['embed' => true]); // `true` because we trust what we loaded through the controller.
                    }
                    if (!empty($options['errors'])) {
                        $errors = [];
                        $errorCode = 500;
                        foreach ($options['errors'] as $e) {
                            $serializedError = $serializeError($e);
                            $errors[] = $serializedError;
                            $errorCode = $serializedError['code'];
                        }
                        $payload->errors($errors);
                        if ($response) {
                            $response->status($errorCode);
                        }
                    }
                    $json = $payload->serialize();
                } catch(Throwable $e) {
                    $serializedError = $serializeError($e);
                    $json = ['errors' => [$serializedError]];
                    $response->status($serializedError['code']);
                }
                return Media::encode('json', $json);
            },
            'decode' => function($data, $options = []) {
                $defaults = [
                    'array' => true,
                    'depth' => 512,
                    'flag'  => 0
                ];
                $options += $defaults;
                if (!$data) {
                    return;
                }
                $result = json_decode($data, $options['array'], $options['depth'], $options['flag']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException(json_last_error_msg());
                }
                $key = $_GET['key'] ?? null;
                $payload = Payload::parse($result, $key === 'cid' ? 'cid' : 'id');
                return $payload->export(null);
            }
        ]);

        Media::set('csv', ['text/csv'], [
            'encode' => function($data, $options = [], $response = null) {
                $defaults = [
                    'depth' => 512,
                    'flag'  => 0
                ];
                $options += $defaults;
                if (!empty($options['errors'])) {
                    $e = reset($options['errors']);
                    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 499;
                    $data = [
                        'error' => $e->getMessage(),
                        'code'  => $errorCode,
                        'file'  => $e->getFile() . "({$e->getLine()})"
                    ];
                    if ($response) {
                        $response->status($errorCode);
                    }
                }

                $result = '';
                if (!$data) {
                    return $result;
                }

                $data = isset($data[0]) ? $data : [$data];
                $template = reset($data);

                // Hack to handle false as 0 instead of an empty string
                foreach ($data as $key => $value) {
                    foreach ($value as $k => $v) {
                        if ($v === false) {
                            $data[$key][$k] = '0';
                        }
                    }
                }

                $keys = array_keys($template);

                $result = '';
                $fp = fopen('php://temp', 'r+b');

                try {
                    fputcsv($fp, $keys, ';', '"');

                    foreach ($data as $item) {
                        fputcsv($fp, array_values($item), ';', '"');
                    }
                    rewind($fp);
                    $result = stream_get_contents($fp);
                } finally {
                    if (is_resource($fp)) {
                        fclose($fp);
                    }
                }
                return $result;
            },
            'decode' => function($data, $options = []) {
                $data = str_getcsv($data, "\n"); //parse the rows
                foreach ($data as $key => $value) {
                    $data[$key] = str_getcsv($value, ';');
                }

                array_walk($data, function(&$a) use ($data) {
                  $a = array_combine($data[0], $a);
                });
                array_shift($data);
                return $data;
            }
        ]);
    });

    beforeEach(function() use ($connection) {

        $this->response = new Response();

        $this->connection = $connection;
        $this->fixtures = new Fixtures([
            'connection' => $connection,
            'fixtures'   => [
                'image'          => 'Lead\Resource\Spec\Fixture\Schema\Image'
            ]
        ]);

    });

    afterEach(function() {
        $this->fixtures->drop();
        $this->fixtures->reset();
    });

    afterAll(function() {
        Media::reset();
    });

    it("negociates GET responses in json", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset/1',
            'method' => 'GET',
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        $route = $r->route($request, 'GET');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"id":1,"cid":"I1","gallery_id":1,"name":"amiga_1200.jpg","title":"Amiga 1200"}');
    });

    it("negociates GET responses in json-api", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset/1',
            'method' => 'GET',
            'headers' => [
                'Accept' => 'application/vnd.api+json'
            ]
        ]);
        $route = $r->route($request, 'GET');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"data":{"type":"Image","id":1,"exists":true,"attributes":{"cid":"I1","gallery_id":1,"name":"amiga_1200.jpg","title":"Amiga 1200"},"links":{"self":"\\/\\/localhost\\/image\\/1"}}}');
    });

    it("throws an exception when trying to negociates GET responses with an unsupported mime", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset/1',
            'method' => 'GET',
            'headers' => [
                'Accept' => 'application/octet-stream'
            ]
        ]);
        $route = $r->route($request, 'GET');

        $closure = function() use ($route) {
            $route->dispatch($this->response);
        };

        expect($closure)->toThrow(new ResourceException('Unsupported `application/octet-stream` as Accept header, supported mimes are `application/json, application/vnd.api+json, text/csv`.', 422));
    });

    it("negociates POST request in json", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"id":6,"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}');
    });

    it("negociates POST request in json-api and response in json", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/vnd.api+json'
            ],
            'body' => '{"data":{"type":"Image","attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}}}'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"id":6,"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}');
    });

    it("negociates POST request in json-api and response in json-api", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json'
            ],
            'body' => '{"data":{"type":"Image","attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}}}'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"data":{"type":"Image","id":6,"exists":true,"attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"},"links":{"self":"\\/\\/localhost\\/image\\/6"}}}');
    });

    it("negociates POST request in csv and response in csv", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'text/csv',
                'Content-Type' => 'text/csv'
            ],
            'body' => "cid;gallery_id;name;title\nA2600;1;amiga_2600.jpg;Amiga 2600"
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe("id;cid;gallery_id;name;title\n6;A2600;1;amiga_2600.jpg;\"Amiga 2600\"\n");
    });

    it("negociates POST request in json with bulk data", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => '[{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"},{"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"}]'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('[{"id":6,"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"},{"id":7,"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"}]');
    });

    it("negociates POST request in json-api and response in json with bulk data", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/vnd.api+json'
            ],
            'body' => '{"data":[{"type":"Image","attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}},{"type":"Image","attributes":{"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"}}]}'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('[{"id":6,"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"},{"id":7,"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"}]');
    });

    it("negociates POST request in json-api and response in json-api with bulk data", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json'
            ],
            'body' => '{"data":[{"type":"Image","attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"}},{"type":"Image","attributes":{"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"}}]}'
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe('{"data":[{"type":"Image","id":6,"exists":true,"attributes":{"cid":"A2600","gallery_id":1,"name":"amiga_2600.jpg","title":"Amiga 2600"},"links":{"self":"\\/\\/localhost\\/image\\/6"}},{"type":"Image","id":7,"exists":true,"attributes":{"cid":"A2700","gallery_id":1,"name":"amiga_2700.jpg","title":"Amiga 2700"},"links":{"self":"\\/\\/localhost\\/image\\/7"}}]}');
    });

    it("negociates POST request in csv and response in csv with bulk data", function() {

        $this->fixtures->populate('image');

        $r = $this->router;
        $request = new Request([
            'path'   => 'asset',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'text/csv',
                'Content-Type' => 'text/csv'
            ],
            'body' => "cid;gallery_id;name;title\nA2600;1;amiga_2600.jpg;Amiga 2600\nA2700;1;amiga_2700.jpg;Amiga 2700"
        ]);
        $route = $r->route($request, 'POST');

        $route->dispatch($this->response);
        expect($this->response->body())->toBe("id;cid;gallery_id;name;title\n6;A2600;1;amiga_2600.jpg;\"Amiga 2600\"\n7;A2700;1;amiga_2700.jpg;\"Amiga 2700\"\n");
    });

});