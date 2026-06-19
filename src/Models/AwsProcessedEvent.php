<?php

namespace LMendes\LaravelAwsMarketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Default Eloquent model recording processed events for webhook deduplication. Swap it through
 * `marketplace-aws.persistence.idempotency.model`. A row exists for every event_key that has been
 * processed.
 *
 * @property string $event_key
 * @property ?\Illuminate\Support\Carbon $processed_at
 */
class AwsProcessedEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('marketplace-aws.persistence.idempotency.table', 'aws_marketplace_processed_events');
    }
}
