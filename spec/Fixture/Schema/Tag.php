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
            ['id' => 1, 'name' => 'High Tech'],
            ['id' => 2, 'name' => 'Sport'],
            ['id' => 3, 'name' => 'Computer'],
            ['id' => 4, 'name' => 'Art'],
            ['id' => 5, 'name' => 'Science'],
            ['id' => 6, 'name' => 'City']
        ]);
    }
}
