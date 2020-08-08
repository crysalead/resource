<?php
namespace Lead\Resource\Spec\Fixture\Model;

class Gallery extends BaseModel
{
    protected static function _define($schema)
    {
        $schema->column('id', ['type' => 'serial']);
        $schema->column('cid', ['type' => 'string', 'null' => true]);
        $schema->column('name', ['type' => 'string']);

        $schema->hasOne('detail', GalleryDetail::class, [
            'keys' => ['id' => 'gallery_id']
        ]);

        $schema->hasMany('images', Image::class, [
            'keys' => ['id' => 'gallery_id']
        ]);
    }
}
