<?php
use Lead\Box\Box;
use Chaos\Database\Adapter\Sqlite;

date_default_timezone_set('UTC');

$box = box('resource.spec', new Box());
$box->factory('source.database.sqlite', function() {
    $connection = new Sqlite();

    $handlers = [
        'string' => function($value, $options = []) {
            return (string) $value;
        },
        'integer' => function($value, $options = []) {
            return (int) $value;
        },
        'float' => function($value, $options = []) {
            return (float) $value;
        },
        'date' => function($value, $options = []) {
            return $this->convert('array', 'datetime', $value, ['format' => 'Y-m-d']);
        },
        'datetime' => function($value, $options = []) {
            $options += ['format' => 'Y-m-d H:i:s'];
            $format = $options['format'];
            if ($value instanceof DateTime) {
                return $value->format($format);
            }
            return date($format, is_numeric($value) ? $value : strtotime($value));
        },
        'boolean' => function($value, $options = []) {
            return $value;
        },
        'null' => function($value, $options = []) {
            return;
        }
    ];

    $connection->formatter('json', 'id',        $handlers['integer']);
    $connection->formatter('json', 'serial',    $handlers['integer']);
    $connection->formatter('json', 'integer',   $handlers['integer']);
    $connection->formatter('json', 'float',     $handlers['float']);
    $connection->formatter('json', 'decimal',   $handlers['string']);
    $connection->formatter('json', 'date',      $handlers['date']);
    $connection->formatter('json', 'datetime',  $handlers['datetime']);
    $connection->formatter('json', 'boolean',   $handlers['boolean']);
    $connection->formatter('json', 'null',      $handlers['null']);
    $connection->formatter('json', '_default_', $handlers['string']);

    return $connection;
});
