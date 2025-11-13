<?php

namespace Doppar\Queue\Models;

use Phaseolies\Database\Entity\Model;
use Phaseolies\Database\Entity\Builder;

class QueueJob extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'queue_jobs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $creatable = [
        'queue',
        'payload',
        'attempts',
        'reserved_at',
        'available_at',
        'created_at',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timeStamps = false;

    /**
     * Scope a query to only include available jobs.
     *
     * @param \Phaseolies\Database\Entity\Builder $query
     * @param string $queue
     * @return \Phaseolies\Database\Entity\Builder
     */
    public function __available(Builder $query, string $queue = 'default'): Builder
    {
        return $query->where('queue', $queue)
            ->where(function ($q) {
                $q->whereNull('reserved_at')
                    ->orWhere('reserved_at', 0);
            })
            ->where('available_at', '<=', time())
            ->orderBy('id', 'asc');
    }

    /**
     * Mark the job as reserved.
     *
     * @return bool
     */
    public function reserve(): bool
    {
        $this->reserved_at = time();
        $this->attempts += 1;

        return $this->save();
    }

    /**
     * Release the job back to the queue.
     *
     * @param int $delay
     * @return bool
     */
    public function release(int $delay = 0): bool
    {
        $this->reserved_at = null;
        $this->available_at = time() + $delay;

        return $this->save();
    }

    /**
     * Delete the job from the queue.
     *
     * @return bool|null
     */
    public function deleteJob(): ?bool
    {
        return $this->delete();
    }
}
