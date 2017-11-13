<?php
namespace Lead\Resource\Router;

use Lead\Net\Http\Cgi\Request;
use Lead\Inflector\Inflector;
use Lead\Router\RouterException;

class ResourceStrategy
{
    /**
     * Mapping beetwen the identifier name in the route pattern
     * and the resource identifer name.
     *
     * @var string
     */
    protected $_key = 'id';

    /**
     * Keys regexp pattern format.
     *
     * @var string
     */
    protected $_format = '[^/:][^/]*';

    /**
     * Mapping beetwen the relation identifier name in the route pattern
     * and the resource identifer name.
     *
     * @var string
     */
    protected $_relations = '[^/]+/[^/:][^/]*';

    /**
     * Resource controller class name suffix.
     *
     * @var string
     */
    protected $_suffix = 'Controller';

    /**
     * Constructor
     *
     * @param array  $config The config array
     */
    public function __construct($config = [])
    {
        $defaults = [
            'key'       => 'id',
            'suffix'    => 'Controller',
            'format'    => '[^/:][^/]*',
            'relations' => '[^/]+/[^/:][^/]*'
        ];
        $config += $defaults;

        $this->_key = $config['key'];
        $this->_relations = $config['relations'];
        $this->_format = $config['format'];
        $this->_suffix = $config['suffix'];
    }

    /**
     * The routing strategy, it creates all necessary routes to match RESTFul URLs for the
     * provided resource name.
     *
     * @param object $router   The router instance.
     * @param string $resource The resource name.
     * @param array  $options  The options array.
     */
    public function __invoke($router, $resource, $options = [])
    {
        $options += [
            'name'      => $resource,
            'key'       => $this->_key,
            'format'    => $this->_format,
            'relations' => $this->_relations,
            'action' => ':{action}'
        ];
        $slug = Inflector::dasherize(Inflector::underscore($resource));
        $path = '{resource:' . $slug . '}';

        $placeholder = '{id:' . $options['format'] . '}';
        $rplaceholder = '{relations:' . $options['relations'] . '}';

        $pattern = '[/' . $rplaceholder . ']*/' . $path . '[/' . $placeholder . ']' . '[/' . $options['action'] . ']';

        $options['params'] = ['resource' => $slug];

        return $router->bind($pattern, $options, function($route) use ($router, $resource, $options) {
            return $this->dispatch($resource, $route, $router, $options);
        });
    }

    /**
     * The dispatching strategy, when an URL matches a RESTFul route, it instantiates a
     * resource and call the `__invoke()` method on it.
     *
     * @param string $resource The resource name.
     * @param object $route    The route who matched the request.
     * @param object $router   The router instance.
     * @param array  $options  An option array.
     * @param mixed            The data response.
     */
    public function dispatch($resource, $route, $router, $options)
    {
        $resource = $route->namespace . $resource . $this->_suffix;
        if (!class_exists($resource)) {
            throw new RouterException("Resource class `{$resource}` not found.");
        }
        $instance = $route->dispatched = new $resource($options + [
            'suffix' => $this->_suffix,
            'router' => $router
        ]);
        if (is_array($route->request)) {
            $route->request = new Request(array_filter($route->request, function($value) {
                return $value !== '*';
            }));
        }
        $route->request->params($route->params);
        if (!isset($route->request->data)) {
            $route->request->data = null;
        }
        return $instance($route->request, $route->response);
    }

}
