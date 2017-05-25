<?php
namespace Lead\Resource;

use Throwable;
use Psr\Http\Message\ResponseInterface;
use Lead\Resource\ResourceException;

/**
 * Resource oriented controller.
 */
class Controller
{
    /**
     * The attached router
     *
     * @var object|null
     */
    public $router = null;

    /**
     * Request.
     *
     * @var mixed
     */
    public $request = null;

    /**
     * Response.
     *
     * @var mixed
     */
    public $response = null;

    /**
     * The format to use for rendering response. If `true` Performs a Content-Type negotiation
     * with the request to find the best matching type.
     *
     * @var string
     */
    protected $_formats = true;

    /**
     * Associative array of variables to be sent to the view.
     *
     * @see Resource::data()
     * @var array
     */
    protected $_data = [];

    /**
     * Defines the layout template to be rendered.
     *
     * @var string
     */
    protected $_layout = null;

    /**
     * Defines the template to be rendered. If `null`, the action name is used as template value.
     *
     * @var string|null
     */
    protected $_template = null;

    /**
     * The identifier field name.
     *
     * @var string
     */
    protected $_key = 'id';

    /**
     * Suffix to ignore.
     *
     * @see Controller::name()
     *
     * @var string
     */
    protected $_suffix = 'Controller';

    /**
     * The handlers.
     *
     * @var array
     */
    protected $_handlers = [];

    /**
     * Default HTTP verb / action mapping.
     *
     * Each action can take an array of required route's param.
     * `true` indicates that the key variable is required (i.e `'id'` by default).
     *
     * Example of allowed syntax:
     * - `'view' => 'id'`
     * - `'view' => ['id']`
     * - `'view' => ['param1', 'param2', 'param3']`
     *
     * @var array
     */
    protected $_methods = [
        'GET'    => ['view'   => true, 'index' => null],
        'POST'   => ['add'    => null],
        'PUT'    => ['edit'   => null],
        'PATCH'  => ['edit'   => null],
        'DELETE' => ['delete' => null]
    ];

    /**
     * State transitions / HTTP code mapping.
     *
     * @see Resource::handlers()
     *
     * @var array
     */
    protected $_stateTransitions = [
        [
            [[], [], 200]
        ],
        'add' => [
            [[], ['success' => true], 201],
            [['exists' => false], ['exists' => true], 201],
            [[], ['valid' => false], 422],
            [['exists' => false], ['exists' => false], 500]
        ],
        'edit' => [
            [
                ['exists' => true],
                ['exists' => true, 'valid' => true, 'success' => true],
                200
            ],
            [[], ['valid' => false], 422],
            [[], ['success' => false], 500]
        ],
        'delete' => [
            [['exists' => true], ['exists' => false], 204],
            [[], ['exists' => true], 424],
            [[], ['success' => false], 424]
        ]
    ];

    /**
     * If set, overrides the status extracted from state transitions.
     *
     * @var integer
     */
    protected $_status = null;

    /**
     * Constructor
     *
     * @param array $config The config array.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'key'           => 'id',
            'suffix'        => 'Controller',
            'handlers'      => $this->_handlers(),
            'router'        => null
        ];
        $config += $defaults;

        $this->_key = $config['key'];
        $this->_suffix = $config['suffix'];
        $this->handlers($config['handlers']);
        $this->router($config['router']);
    }

    /**
     * Gets/sets the attached router.
     *
     * @param  string      $router The router to set or none to get the setted one.
     * @return string|self
     */
    public function router($router = null)
    {
        if (!func_num_args()) {
            return $this->_router;
        }
        $this->_router = $router;
        return $this;
    }

    /**
     * Gets the base name of the resource class, i.e. "Post".
     *
     * @return string Returns the class name of the resource, without the namespace name.
     */
    public function name()
    {
        $name = basename(str_replace('\\', '/', get_called_class()));
        return preg_replace('/' . $this->_suffix . '$/', '', $name);
    }

    /**
     * Gets the model class binding for this resource.
     *
     * @return string Returns the fully qualified class name of the model to which this `Resource` class is bound.
     */
    public function binding()
    {
        $name = $this->name();
        throw new ResourceException("The `{$name}` resource has no model binding defined.", 500);
    }

    /**
     * Responds to a request.
     *
     * @param  object $request  The request.
     * @param  object $response The reponse.
     * @return mixed            The reponse.
     */
    public function __invoke($request, $response)
    {
        $this->request = $request;
        $this->response = $response;

        $method = $request->method();
        $action = $this->_action($method, $request->params());

        $this->_format($request, $response, $action);

        if (!is_callable([$this, $action])) {
            $name = $this->name();
            throw new ResourceException("The `{$name}` resource does not handle `{$action}` requests.", 405);
        }

        $controller = $this->name();
        $name = lcfirst($controller);

        $success = true;
        $status = 500;
        $errors = [];
        $resources = [];

        foreach ($this->args($action, $request) as $args) {
            try {
                $status = $this->_run($action, $args, $args[0]);
                $resources[] = $args[0];

                if (count($resources) > 1 && $method === 'GET') {
                    throw new ResourceException("Bluk actions are not available through GET queries.");
                }
            } catch (Throwable $e) {
                $success = false;
                $errors[] = $e;
            }
        }

        if (count($resources) === 1) {
            $this->_data[$name] = reset($this->_data[$name]);
            $resource = reset($resources);
        } else {
            $resource = $this->_collection($resources);
        }

        $status = $this->status() ? $this->status() : $status;

        $classname = get_called_class();

        $options = [
            'response'    => $response,
            'namespace'   => substr($classname, 0, strrpos($classname, '\\')),
            'controller'  => $controller,
            'action'      => $action,
            'name'        => $name,
            'status'      => $status,
            'template'    => $this->_template ?: strtolower($controller) . '/' . $action,
            'layout'      => $this->_layout,
            'errors'      => $errors,
            'data'        => $this->data(),
            'bulk'        => count($resources) > 1
        ];

        $this->_render($resource, $options);

        return $success;
    }

    /**
     * Formats the response according the invoked action.
     * Performs a content negotiation with the request if the applicable format is `true`;
     *
     * @param object $request  A request instance.
     * @param object $response A response instance.
     * @param string $action   The action name.
     */
    protected function _format($request, $response, $action)
    {
        if (!is_array($this->_formats)) {
            $format = $this->_formats;
        } elseif (isset($this->_formats[$action])) {
            $format = $this->_formats[$action];
        } elseif (isset($this->_formats[0])) {
            $format = $this->_formats[0];
        } else {
            $format = true;
        }
        if ($format === true) {
            $request->negotiate();
            $response->negotiate($request);
        } elseif ($format) {
            $request->format($format);
            $response->format($format);
        }
    }

    /**
     * Executes a single action and returns the corresponding status code.
     *
     * @param  string  $action   The action to execute.
     * @param  array   $args     The arguments to pass.
     * @param  mixed   $resource The resource.
     * @return integer           A status code.
     */
    protected function _run($action, $args, &$resource)
    {
        $controller = $this->name();
        $name = lcfirst($controller);

        $transitions = [];
        $transitions[] = $this->state($resource);
        $success = call_user_func_array([$this, $action], $args);
        $transitions[] = $this->state($resource, $success === true ? compact('success') : []);

        if (in_array($action, array_keys($this->_stateTransitions), true)) {
            if (!is_bool($success)) {
                throw new ResourceException("`{$action}` queries must return a boolean value.");
            }
        } else {
            $resource = $success !== null ? $success : $resource;
        }

        $resource = $this->_fetch($resource);

        $this->_data[$name][] = $resource;

        return $this->_transitionsStatus($action, $transitions);
    }

    /**
     * Returns the action to dispatch based on the HTTP verb and route's params.
     *
     * @param  string $method The HTTP verb
     * @param  array  $params The URL named parameters
     * @return string         The action to dispatch
     */
    protected function _action($method, $params = [])
    {
        if (empty($params['action'])) {
            foreach ($this->_methods[$method] as $action => $value) {
                if ($value === true && isset($params['id'])) {
                    return $action;
                }
                $value = (array) $value;
                if (array_intersect($value, array_keys($params)) !== $value) {
                    continue;
                }
                return $action;
            }
            throw new ResourceException("The `{$name}` resource could not process the request because the parameters are invalid.", 405);
        }
        $action = $params['action'];
        $methods = array_diff(get_class_methods($this), get_class_methods(__CLASS__));
        if (!in_array($action, $methods) || strpos($action, '_') === 0) {
            $name = $this->name();
            throw new ResourceException("The `{$name}` resource doesn't understand how to do `{$action}`.", 405);
        }
        return $action;
    }

    /**
     * Gets sets view data.
     *
     * @param  array      $data Sets of `<variable name> => <variable value>` to pass to view layer.
     * @return array|self       The attached data on get or `$this` on set.
     */
    public function data($data = [])
    {
        if (!func_num_args()) {
            return $this->_data;
        }
        $this->_data = (array) $data + $this->_data;
        return $this;
    }

    /**
     * Gets/sets the response status. It overrides the one extracted from state transitions.
     *
     * @param  string      $status The status to set or none to get the setted one.
     * @return string|self
     */
    public function status($status = null)
    {
        if (!func_num_args()) {
            return $this->_status;
        }
        $this->_status = $status;
        return $this;
    }

    /**
     * Generator returning the list of arguments to pass to action methods.
     * Override this method is you need to perform some ACL check.
     *
     * @see Resource::handlers()
     *
     * @param  string $action The action method name
     * @param  object $requet The Request instance.
     * @return object         The action method resource argument.
     */
    public function args($action, $request)
    {
        $required = !empty($request->params['id']);

        $options = [
            'name'       => $this->name(),
            'binding'    => $this->binding(),
            'action'     => $action
        ];

        $name = $options['name'];
        $binding = $options['binding'];

        if (!class_exists($binding)) {
            throw new ResourceException("Could not find binding class for resource `{$name}`.", 500);
        }

        $handlers = $this->handlers('actions');

        if (isset($handlers[$action])) {
            $handler = $handlers[$action];
        } elseif (!in_array($action, ['index', 'view', 'add', 'edit', 'delete'])) {
            $handler = null;
        } elseif (isset($handlers[0])) {
            $handler = $handlers[0];
        } else {
            $handler = null;
        }

        if (!$handler) {
            yield [ $binding, $request ];
            return;
        }
        foreach (call_user_func($handler, $request, $options) as $args) {
            yield $args;
        }
    }

    /**
     * Gets/sets handlers.
     *
     * `'state'   : To return an approriate HTTP status, the state handler is used to inspect the state
     *              of a resource before and after the execution of a resource's action.
     *              Before returning the response, the state transitions are inspected in order to set
     *              the appropriate HTTP status for the response.
     *
     *              The state returned by the state handler must contain the following keys:
     *              - `'exists'`   : a boolean indicating whether the resource is an already persisted resource or not.
     *              - `'validate'` : a boolean indicating if the validation step passed or not.
     *
     * `'actions'`: Instead of passing a resource id to the action methods, the action handlers are used to
     *              generate arguments for action method. Arguments can be created or loaded resources
     *              based on route params.
     *              Actions andlers are stored in an array prefixed by action method names. The one with a `0` key
     *              will be used as default handler.
     *              handlers must reuturn an array of array to support bulk requests. Indeed actions
     *              will be executed as many time as the returned list contains arguments array.
     *
     * @param  string|array $handler The handler name to get or none to get all handlers. If
     *                               `$handler` is an array it'll be merged into existing handlers.
     * @return mixed                 The found handler if `$handler` is a string or the array of
     *                               existing handlers otherwise.
     */
    public function handlers($handler = null)
    {
        if (is_array($handler)) {
            $this->_handlers = $handler + $this->_handlers;
            return $this;
        }
        if ($handler && is_string($handler)) {
            return isset($this->_handlers[$handler]) ? $this->_handlers[$handler] : null;
        }
        return $this->_handlers;
    }

    /**
     * Handlers for creating/loading the resource to pass as arguments of action methods.
     *
     * This method should be overrided to provide handlers for creating/loading a resource according to
     * the datasource layer API.
     *
     * @see Resource::args()
     *
     * @return array
     */
    protected function _handlers()
    {
        return [];
    }

    /**
     * Gets the state of a resource instance.
     *
     * @param  object $instance A resource instance.
     * @param  array  $default  Default state.
     * @return array            The corresponding resource's state.
     */
    public function state($instance, $default = [])
    {
        if ($handler = $this->handlers('state')) {
            return $handler($instance) + $default;
        }
        return $default;
    }

    /**
     * Interpolates a response status from an action name and the resource state transitions.
     *
     * @param  string  $action      An action name.
     * @param  array   $transitions The resource state transitions.
     * @return integer              A HTTP response code.
     */
    protected function _transitionsStatus($action, $transitions)
    {
        $events = isset($this->_stateTransitions[$action]) ? $this->_stateTransitions[$action] : $this->_stateTransitions[0];

        foreach ($events as $transition) {
            foreach ($transitions as $i => $state) {
                if (array_intersect_assoc($transition[$i], $state) !== $transition[$i]) {
                    continue 2;
                }
            }
            return $transition[2];
        }
    }

    /**
     * The render method.
     *
     * @param array $options The render option array.
     */
    protected function _render($resource, $options = [])
    {
        if (!empty($options['status'])) {
            $this->response->status($options['status']);
        }
        $this->response->set($resource, $options);

        if (!isset($response->headers['Vary'])) {
            $response->headers['Vary'] = ['Accept', 'Accept-Encoding'];
        }
    }

    /**
     * Fetches lazy-loadable resource instances by loading their data when apply.
     *
     * @param  mixed $resource A resource instance.
     * @return mixed           A resolved resource instance.
     */
    protected function _fetch($resource)
    {
        return $resource;
    }

    /**
     * Wraps passed parameter into a collection
     *
     * @param  Array  $resources An array of resources.
     * @return Object            The collection instance.
     */
    protected function _collection($resources)
    {
        return $resources;
    }
}
