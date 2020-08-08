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
            ['id' => 1, 'cid' => 'G1', 'name' => 'Foo Gallery'],
            ['id' => 2, 'cid' => 'G2', 'name' => 'Bar Gallery']
        ]);
    }
}
