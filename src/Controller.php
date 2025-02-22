<?php
namespace Lead\Resource;

use Throwable;
use Psr\Http\Message\ResponseInterface;
use Lead\Resource\ResourceException;
use Lead\Net\Http\Media;

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
     * Associative array of variables to be sent as meta.
     *
     * @var array
     */
    protected $_meta = [];

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
     * The url identifier field name.
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
        'FETCH'  => ['view'   => true, 'index' => null],
        'GET'    => ['view'   => true, 'index' => null],
        'POST'   => ['edit'   => null],
        'PUT'    => ['edit'   => null],
        'PATCH'  => ['edit'   => null],
        'DELETE' => ['delete' => null]
    ];

    protected $_requestRules = null;

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
            [['exists' => false], ['exists' => false], 599]
        ],
        'edit' => [
            [
                ['exists' => true],
                ['exists' => true, 'valid' => true, 'success' => true],
                200
            ],
            [[], ['valid' => false], 422],
            [[], ['success' => false], 599]
        ],
        'delete' => [
            [['exists' => true], ['exists' => false], 204],
            [[], ['exists' => true], 424],
            [[], ['success' => false], 424]
        ]
    ];

    /**
     * Currently processed action
     *
     * @var string
     */
    protected $_action = null;

    /**
     * If set, overrides the status extracted from state transitions.
     *
     * @var integer
     */
    protected $_status = null;

    /**
     * The format to use for processing the request. Performs a Content-Type negotiation
     * with the request to find the best matching type.
     *
     * @var string
     */
    protected function _inputs()
    {
        return [];
    }

    /**
     * The format to use for rendering response. Performs an Accept negotiation
     * with the request to find the best matching type.
     *
     * @var string
     */
    protected function _outputs()
    {
        return [];
    }

    /**
     * Allowed filters by action.
     *
     * @return array
     */
    protected function _requestRules($rules)
    {
    }

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
        $this->_requestRules = new RequestRules($this);
        $this->_requestRules($this->_requestRules);
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
            return $this->router;
        }
        $this->router = $router;
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
        throw new ResourceException("The `{$name}` resource has no model binding defined.", 599);
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
        $this->_action = $this->_action($method, $request->params());

        $this->_negociateRequest($request, $this->_action);
        $this->_negociateResponse($request, $response, $this->_action);

        $isSet = false;
        $success = true;
        $errors = [];
        $validationErrors = [];
        $resources = [];
        $resource = null;
        $status = null;

        $controller = $this->name();
        $name = lcfirst($controller);

        try {
            $argsList = $this->args($this->_action, $request, $validationErrors);

            // since $argsList may be empty make sure errors are populated
            foreach ($validationErrors as $i => $validationError) {
                if (!empty($validationErrors[$i])) {
                    $status = 422;
                    $errors[$i] = [
                        'status' => '422',
                        'title'  => 'Unprocessable Entity',
                        'data'   => $validationErrors[$i]
                    ];
                } else {
                    $errors[$i] = null;
                }
            }

            foreach ($argsList as $i => $args) {
                $resource = null;

                try {
                    if (empty($validationErrors[$i])) {
                        $transitionName = array_shift($args);
                        $currentStatus = $this->_run($this->_action, $args, $args[0], $transitionName);
                        $resource = $args[0];
                        $resources[] = $resource;
                        if ($status <= 400) {
                            $status = $currentStatus;
                        }
                    }

                    if (!empty($validationErrors[$i]) || ($currentStatus === 422 && $this->_isDocument($resource))) {
                        $status = 422;
                        $errors[$i] = [
                            'status' => '422',
                            'title'  => 'Unprocessable Entity',
                            'data'   => $validationErrors[$i] ?? $resource->errors(['embed' => true])
                        ];
                    } else {
                        $errors[$i] = null;
                    }

                    if (count($resources) > 1 && $method === 'GET') {
                        throw new ResourceException("Bluk actions are not available through GET queries.");
                    }
                } catch (Throwable $e) {
                    $success = false;
                    $errors[$i] = $e;
                    $status = $status <= 400 ? ($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 499) : $status;
                }
            }

            if (count($resources) === 1) {
                $this->_data[$name] = reset($this->_data[$name]);
                $resource = reset($resources);
                $isSet = $this->_isSet($resource);
            } else {
                $resource = $this->_collection($resources);
                $isSet = true;
            }
        } catch (Throwable $e) {
            $success = false;
            $errors = [$e];
            $status = $status <= 400 ? ($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 499) : $status;
        }

        if (!$status && $method === 'DELETE') {
            $status = 204;
        }
        if ($status && $method === 'PUT' && count($errors) > 1) {
            $status = 200;
        }

        // If status has been overrided in controller method to not use $status value.
        if (!$this->status() && $status) {
            $this->status($status);
        }

        $classname = get_called_class();

        if (!array_filter($errors)) {
            $errors = [];
        }

        $options = [
            'response'    => $response,
            'namespace'   => substr($classname, 0, strrpos($classname, '\\')),
            'controller'  => $controller,
            'action'      => $this->_action,
            'name'        => $name,
            'status'      => $this->status(),
            'template'    => $this->_template ?: strtolower($controller) . '/' . $this->_action,
            'layout'      => $this->_layout,
            'errors'      => $errors,
            'data'        => $this->data(),
            'set'         => $isSet
        ];

        $this->_render($resource, $options);

        return $response;
    }

    /**
     * Formats the request according the invoked action.
     * Performs a content negotiation with the request if the applicable format is `true`;
     *
     * @param object $request  A request instance.
     * @param string $action   The action name.
     */
    protected function _negociateRequest($request, $action)
    {
        $inputs = $this->_inputs();
        if (isset($inputs[$action])) {
            $input = $inputs[$action];
        } elseif (isset($inputs[0])) {
            $input = $inputs[0];
        } else {
            $input = true;
        }

        if (!$request->body()) {
            return;
        }
        if ($input === true) {
            $request->negotiate();
        } else {
            $inputs = is_array($input) ? $input : [$input];
            $mime = $request->mime();
            $supportedMimes = [];
            foreach ($inputs as $format) {
                $supportedMimes[$format] = Media::mime($format);
            }
            if ($format = array_search($mime, $supportedMimes)) {
                $request->format($format);
                return;
            }
            $supportedMimes = join(', ', $supportedMimes);
            throw new ResourceException("Unsupported `{$mime}` Content-Type, it only `{$supportedMimes}` are supported.", 422);
        }
    }

    /**
     * Formats the response according the invoked action.
     * Performs a content negotiation with the response if the applicable format is `true`;
     *
     * @param object $response A response instance.
     * @param string $action   The action name.
     */
    protected function _negociateResponse($request, $response, $action)
    {
        $outputs = $this->_outputs();
        if (isset($outputs[$action])) {
            $output = $outputs[$action];
        } elseif (isset($outputs[0])) {
            $output = $outputs[0];
        } else {
            $output = true;
        }

        if ($output === true) {
            $response->negotiate($request);
        } else {
            $outputs = is_array($output) ? $output : [$output];
            if (!$request->hasHeader('Accept')) {
                $response->format(reset($outputs));
                return;
            }
            $supportedMimes = [];
            foreach ($outputs as $format) {
                $supportedMimes[$format] = Media::mime($format);
            }
            foreach ($request->accepts() as $mime => $value) {
                if ($mime === '*/*') {
                    $response->format(reset($outputs));
                    return;
                } elseif ($format = Media::suitable($request, $mime, $outputs)) {
                    $response->format($format);
                    return;
                }
            }
            foreach ($request->accepts() as $mime => $value) {
                $mimes[] = $mime;
            }
            $mimes = join(', ', $mimes);
            $supportedMimes = join(', ', $supportedMimes);
            throw new ResourceException("Unsupported `{$mimes}` as Accept header, supported mimes are `{$supportedMimes}`.", 422);
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
    protected function _run($action, $args, &$resource, $transitionName)
    {
        $controller = $this->name();
        $name = lcfirst($controller);

        $transitions = [];
        $transitions[] = $this->state($resource);
        $success = call_user_func_array([$this, $action], $args);
        $transitions[] = $this->state($resource, $success === true ? compact('success') : []);

        if (in_array($transitionName, array_keys($this->_stateTransitions), true)) {
            if (!is_bool($success)) {
                throw new ResourceException("`{$action}` queries must return a boolean value.");
            }
        } else {
            $resource = $success !== null ? $success : $resource;
        }

        $resource = $this->_fetch($resource);

        $this->_data[$name][] = $resource;

        return $this->_transitionsStatus($transitionName, $transitions);
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
        $controller = $this->name();
        if (empty($params['action'])) {
            if (!empty($this->_methods[$method])) {
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
            }
            throw new ResourceException("The `{$controller}` resource does not support `{$method}` as HTTP method.");
        }
        $action = $params['action'];
        $methods = array_diff(get_class_methods($this), get_class_methods(__CLASS__));
        if (!in_array($action, $methods) || strpos($action, '_') === 0) {
            throw new ResourceException("The `{$controller}` resource does not handle the `{$action}` action.", 405);
        }
        return $action;
    }

    /**
     * Gets/sets the `'meta'` property.
     *
     * @return array
     */
    public function meta($meta = [])
    {
        if (!func_num_args()) {
            return $this->_meta;
        }
        $this->_meta = $meta;
        return $this;
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
    public function args($action, $request, &$validationErrors = [])
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
            throw new ResourceException("Could not find binding class for resource `{$name}`.", 599);
        }

        $handlers = $this->handlers('actions');

        if (isset($handlers[$action])) {
            $handler = $handlers[$action];
        } elseif (isset($handlers[0])) {
            $handler = $handlers[0];
        } else {
            $handler = null;
        }

        $method = $request->method();
        if (!$handler) {
            return [[null, $binding, $method === 'GET' ? $request->query() : $request->get()]];
        }
        return $handler($request, $options, $validationErrors);
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
     * Interpolates a response status from an transition name and the resource state transitions.
     *
     * @param  string  $transitionName An transition name.
     * @param  array   $transitions    The resource state transitions.
     * @return integer                 A HTTP response code.
     */
    protected function _transitionsStatus($transitionName, $transitions)
    {
        $events = isset($this->_stateTransitions[$transitionName]) ? $this->_stateTransitions[$transitionName] : $this->_stateTransitions[0];

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
        $meta = $this->meta();
        $binding = $this->binding();
        $options['meta'] = $meta;
        $options['model'] = $binding;
        $options['request'] = $this->request;
        $options['response'] = $this->response;
        $this->response->set($resource, [], $options);

        $headers = $this->response->headers();
        if (isset($meta['count'])) {
            $headers['X-Total-Count'] = $meta['count'];
        }
        if (!isset($headers['Vary'])) {
            $headers['Vary'] = ['Accept', 'Accept-Encoding'];
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
