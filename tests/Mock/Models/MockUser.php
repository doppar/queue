<?php

namespace Doppar\Queue\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;
use Phaseolies\Database\Entity\Builder;

class MockUser extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $connection = 'default';

    protected $creatable = [
        'name',
        'email',
        'password',
        'api_token',
        'created_at',
        'updated_at',
    ];

    // Hidden fields that should NOT be serialized
    protected $unexposable = [
        'password',
        'api_token',
    ];

    protected $timeStamps = true;

    /**
     * User has many posts
     */
    public function posts()
    {
        return $this->linkMany(MockPost::class, 'user_id', 'id');
    }

    /**
     * User has many comments
     */
    public function comments()
    {
        return $this->linkMany(MockComment::class, 'user_id', 'id');
    }
}
