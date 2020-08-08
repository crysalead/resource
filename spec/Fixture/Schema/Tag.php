<?php
namespace Lead\Resource\Spec\Fixture\Schema;

class Tag extends \Lead\Resource\Spec\Fixture\Fixture
{
    public $_model = 'Lead\Resource\Spec\Fixture\Model\Tag';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'cid' => 'T1', 'name' => 'High Tech'],
            ['id' => 2, 'cid' => 'T2', 'name' => 'Sport'],
            ['id' => 3, 'cid' => 'T3', 'name' => 'Computer'],
            ['id' => 4, 'cid' => 'T4', 'name' => 'Art'],
            ['id' => 5, 'cid' => 'T5', 'name' => 'Science'],
            ['id' => 6, 'cid' => 'T6', 'name' => 'City']
        ]);
    }
}
