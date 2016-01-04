<?php
namespace Lead\Resource\Spec\Suite\Router;

use stdClass;
use Lead\Router\Router;
use Lead\Resource\Router\ResourceStrategy;

describe("ResourceStrategy", function() {

    beforeEach(function() {

        $this->router = new Router();
        $this->router->strategy('resource', new ResourceStrategy());
    });

    it("dispatches resources urls", function() {

        $r = $this->router;
        $r->resource('RoutingTest', ['namespace' => 'Lead\Resource\Spec\Mock']);
        $response = new stdClass();

        $route = $r->route('routing-test', 'GET');
        $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => null
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test/123', 'GET');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => '123',
            'action'   => null
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test/:create', 'GET');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => 'create'
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test', 'POST');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => null
        ]);
        expect($route->request->method())->toBe('POST');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test/123/:edit', 'GET');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => '123',
            'action'   => 'edit'
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test/123', 'PATCH');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => '123',
            'action'   => null
        ]);
        expect($route->request->method())->toBe('PATCH');
        expect($route->response)->toBe($response);

        $route = $r->route('routing-test/123', 'DELETE');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'resource' => 'routing-test',
            'id'       => '123',
            'action'   => null
        ]);
        expect($route->request->method())->toBe('DELETE');
        expect($route->response)->toBe($response);

    });

    it("dispatches resources urls with dependency", function() {

        $r = $this->router;
        $r->resource('RoutingTest', ['namespace' => 'Lead\Resource\Spec\Mock']);
        $response = new stdClass();

        $route = $r->route('relation-name/456/routing-test', 'GET');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'relation' => 'relation-name',
            'rid'      => '456',
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => null
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('relation-name/456/routing-test/:create', 'GET');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'relation' => 'relation-name',
            'rid'      => '456',
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => 'create'
        ]);
        expect($route->request->method())->toBe('GET');
        expect($route->response)->toBe($response);

        $route = $r->route('relation-name/456/routing-test', 'POST');
        $route = $route->dispatch($response);
        expect($route->request->params())->toBe([
            'relation' => null,
            'rid'      => null,
            'relation' => 'relation-name',
            'rid'      => '456',
            'resource' => 'routing-test',
            'id'       => null,
            'action'   => null
        ]);
        expect($route->request->method())->toBe('POST');
        expect($route->response)->toBe($response);

    });

});