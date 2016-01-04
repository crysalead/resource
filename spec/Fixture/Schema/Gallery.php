<?php
namespace Lead\Resource\Spec\Fixture\Schema;

class Gallery extends \Lead\Resource\Spec\Fixture\Fixture
{
    public $_model = 'Lead\Resource\Spec\Fixture\Model\Gallery';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'name' => 'Foo Gallery'],
            ['id' => 2, 'name' => 'Bar Gallery']
        ]);
    }
}
