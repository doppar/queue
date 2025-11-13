<?php

namespace Doppar\Queue\Models;

use Phaseolies\Database\Entity\Model;

class FailedJob extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'failed_jobs';

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
