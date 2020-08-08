<?php
namespace Lead\Resource\Spec\Fixture\Schema;

class Image extends \Lead\Resource\Spec\Fixture\Fixture
{
    public $_model = 'Lead\Resource\Spec\Fixture\Model\Image';

    public function all()
    {
        $this->create();
        $this->records();
    }

    public function records()
    {
        $this->populate([
            ['id' => 1, 'cid' => 'I1', 'gallery_id' => 1, 'name' => 'amiga_1200.jpg', 'title' => 'Amiga 1200'],
            ['id' => 2, 'cid' => 'I2', 'gallery_id' => 1, 'name' => 'srinivasa_ramanujan.jpg', 'title' => 'Srinivasa Ramanujan'],
            ['id' => 3, 'cid' => 'I3', 'gallery_id' => 1, 'name' => 'las_vegas.jpg', 'title' => 'Las Vegas'],
            ['id' => 4, 'cid' => 'I4', 'gallery_id' => 2, 'name' => 'silicon_valley.jpg', 'title' => 'Silicon Valley'],
            ['id' => 5, 'cid' => 'I5', 'gallery_id' => 2, 'name' => 'unknown.gif', 'title' => 'Unknown']
        ]);
    }
}
