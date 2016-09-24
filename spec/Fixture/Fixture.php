<?php
namespace Lead\Resource\Spec\Fixture;

use Chaos\Database\DatabaseException;
use Lead\Set\Set;

class Fixture
{
    /**
     * The connection to the datasource.
     *
     * @var object
     */
    protected $_connection = null;

    /**
     * The meta definition.
     *
     * @var array
     */
    protected $_meta = [];

    /**
     * The schema definition.
     *
     * @var array
     */
    protected $_schema = [];

    /**
     * The cached schema instance.
     *
     * @var object
     */
    protected $_cache = null;

    /**
     * The alter definitions.
     *
     * @var array
     */
    protected $_alters = [];

    /**
     * The model.
     *
     * @var string
     */
    protected $_model = null;

    /**
     * The parent fixtures.
     *
     * @var object
     */
    protected $_fixtures = null;

    /**
     * Constructor.
     *
     * @param array $config Possible options are:
     *                      - `'connection'`  _object_ : The connection instance.
     */
    public function __construct($config = [])
    {
        $defaults = [
            'connection' => null,
            'model'      => $this->_model,
            'meta'       => [],
            'alters'     => $this->_alters
        ];

        $config = Set::merge($defaults, $config);

        $this->_connection = $config['connection'];
        $this->_meta = $config['meta'];
        $this->_alters = $config['alters'];
        $this->_model = $config['model'];

        $model = $this->_model;
        $model::connection($this->connection());
    }

    /**
     * Gets/sets the connection object to which this schema is bound.
     *
     * @return object    $connection Returns a connection instance.
     * @throws Exception             Throws a `DatabaseException` if a connection isn't set.
     */
    public function connection($connection = null)
    {
        if (func_num_args()) {
            return $this->_connection = $connection;
        }
        if (!$this->_connection) {
            throw new DatabaseException("Error, missing connection for this schema.");
        }
        return $this->_connection;
    }

    /**
     * Returns a dynamically created model based on the model class name passed as parameter.
     *
     * @return string A model class name.
     */
    public function model()
    {
        return $this->_model;
    }

    /**
     * Gets the associated schema.
     *
     * @param  array  $options The schema option.
     * @return object          The associated schema instance.
     */
    public function schema()
    {
        $this->_cache = null;

        if ($this->_cache) {
            return $this->_cache;
        }

        $model = $this->model();
        $this->_cache = $model::definition();

        $this->_alterSchema($this->_cache);

        return $this->_cache;
    }

    /**
     * Populates some records.
     *
     * @return string
     */
    public function populate($records = [])
    {
        $model = $this->model();

        if(count(array_filter(array_keys($records), 'is_string'))) {
            $records = [$records];
        }

        $schema = $this->schema();
        $fields = array_fill_keys($schema->fields(), true);

        foreach ($records as $record) {
            $data = $this->_alterRecord($record);
            $data = array_intersect_key($data, $fields);
            $entity = $model::create($data);
            $entity->save();
        }
    }

    /**
     * Formats fields according the alter configuration.
     *
     * @param  array $schema The schema to alter
     * @return array         Returns the modified fields.
     */
    protected function _alterSchema($schema) {
        foreach ($this->_alters as $mode => $values) {
            foreach ($values as $key => $value) {
                switch($mode) {
                    case 'add':
                        $schema->column($key, $value);
                        break;
                    case 'change':
                        if (!$schema->has($key)) {
                            throw new DatabaseException("Can't change the following unexisting field: `'{$key}'`.");
                        }
                        $field = $schema->field($key);
                        if (isset($value['to'])) {
                            $schema->remove($key);
                            $to = $value['to'];
                            unset($value['to']);
                            unset($value['value']);
                            $schema->column($to, $value + $field);
                        }
                        break;
                    case 'drop':
                        $schema->remove($key);
                        break;
                }
            }
        }
        return $schema;
    }

    /**
     * Formats values according the alter configuration.
     *
     * @param  array $record The record array.
     * @return array         Returns the modified record.
     */
    protected function _alterRecord($record = []) {
        $result = array();
        foreach ($record as $name => $value) {
            if (isset($this->_alters['change'][$name])) {
                $alter = $this->_alters['change'][$name];
                if (isset($alter['value'])) {
                    $function = $alter['value'];
                    $value = $function($record[$name]);
                } else {
                    $value = $record[$name];
                }
                if (isset($alter['to'])) {
                    $result[$alter['to']] = $value;
                } else {
                    $result[$name] = $value;
                }
            } else {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    public function create()
    {
        $this->schema()->create();
    }

    public function drop()
    {
        $this->schema()->drop();
    }
}
