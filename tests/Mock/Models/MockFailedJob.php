<?php

namespace Doppar\Queue\Tests\Mock\Models;

use Phaseolies\Database\Entity\Model;

class MockFailedJob extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'failed_jobs';
    protected $connection = 'default';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $creatable = [
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timeStamps = false;
}
