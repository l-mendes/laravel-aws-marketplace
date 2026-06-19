<?php

namespace LMendes\LaravelAwsMarketplace\Repositories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;

class EloquentProcessedEventStore implements ProcessedEventStore
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
    ) {}

    public function isProcessed(string $key): bool
    {
        return $this->model::query()->where('event_key', $key)->exists();
    }

    public function markProcessed(string $key): void
    {
        $now = now();

        $this->model::query()->insertOrIgnore([
            'event_key' => $key,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function prune(DateTimeInterface $before): int
    {
        return $this->model::query()->where('created_at', '<', $before)->delete();
    }
}
