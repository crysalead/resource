<?php
namespace Lead\Resource\Spec\Fixture\Schema;

class GalleryDetail extends \Lead\Resource\Spec\Fixture\Fixture
{
    public $_model = 'Lead\Resource\Spec\Fixture\Model\GalleryDetail';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'description' => 'Foo Gallery Description', 'gallery_id' => 1],
            ['id' => 2, 'description' => 'Bar Gallery Description', 'gallery_id' => 2]
        ]);
    }
}
