<?php
use Lead\Box\Box;
use Chaos\Database\Adapter\Sqlite;

date_default_timezone_set('UTC');

$box = box('resource.spec', new Box());
$box->factory('source.database.sqlite', function() {
    return new Sqlite();
});
