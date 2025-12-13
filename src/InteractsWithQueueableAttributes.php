<?php 

namespace Doppar\Queue;

use Doppar\Queue\Attributes\Queueable;

trait InteractsWithQueueableAttributes
{
    /**
     * Check if the job should be queued based on the Queueable attribute.
     *
     * @return bool
     */
    public function shouldQueue(): bool
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(Queueable::class);

        return !empty($attributes);
    }

    /**
     * Apply the Queueable attribute settings to the job.
     *
     * @return void
     */
    protected function applyQueueableAttributes(): void
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(Queueable::class);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();

            if ($attribute->tries !== null) {
                $this->tries = $attribute->tries;
            }

            if ($attribute->retryAfter !== null) {
                $this->retryAfter = $attribute->retryAfter;
            }

            if ($attribute->delayFor !== null) {
                $this->jobDelay = $attribute->delayFor;
            }

            if ($attribute->onQueue !== null) {
                $this->queueName = $attribute->onQueue;
            }

            if ($attribute->timeout !== null) {
                $this->timeout = $attribute->timeout;
            }
        }
    }
}