<?php
namespace Lead\Resource\Spec\Fixture\Model;

class ImageTag extends BaseModel
{
    protected static function _define($schema)
    {
        $schema->column('id', ['type' => 'serial']);
        $schema->column('image_id', ['type' => 'integer']);
        $schema->column('tag_id', ['type' => 'integer']);

        $schema->belongsTo('image', Image::class, [
            'keys' => ['image_id' => 'id']
        ]);

        $schema->belongsTo('tag', Tag::class, [
            'keys' => ['tag_id' => 'id']
        ]);
    }
}
