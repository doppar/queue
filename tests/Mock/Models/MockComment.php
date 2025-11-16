<?php

namespace Doppar\Queue\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;
use Phaseolies\Database\Entity\Builder;

class MockComment extends Model
{
    protected $table = 'comments';

    protected $primaryKey = 'id';

    protected $connection = 'default';

    protected $creatable = [
        'post_id',
        'user_id',
        'body',
        'created_at',
        'updated_at',
    ];

    protected $timeStamps = true;

    /**
     * Comment belongs to a post
     */
    public function post()
    {
        return $this->bindTo(MockPost::class, 'id', 'post_id');
    }

    /**
     * Comment belongs to a user
     */
    public function user()
    {
        return $this->bindTo(MockUser::class, 'id', 'user_id');
    }
}
