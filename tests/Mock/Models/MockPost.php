<?php

namespace Doppar\Queue\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;

class MockPost extends Model
{
    protected $table = 'posts';

    protected $primaryKey = 'id';

    protected $connection = 'default';

    protected $creatable = [
        'user_id',
        'title',
        'content',
        'created_at',
        'updated_at',
    ];

    protected $timeStamps = true;

    /**
     * Post belongs to a user
     */
    public function user()
    {
        return $this->bindTo(MockUser::class, 'id', 'user_id');
    }

    /**
     * Post has many comments
     */
    public function comments()
    {
        return $this->linkMany(MockComment::class, 'id', 'post_id');
    }
}
