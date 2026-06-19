<?php

namespace LMendes\LaravelAwsMarketplace\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;

/**
 * Deletes processed-event idempotency receipts older than the configured TTL, bounding the
 * aws_marketplace_processed_events table. Schedule it (for example daily) once a TTL is set. Receipts
 * only need to outlive the provider's retry window, so an old receipt is safe to remove: the event it
 * dedupes is effectively never resent.
 */
class PruneProcessedEventsCommand extends Command
{
    protected $signature = 'aws-marketplace:prune-events
        {--days= : Delete receipts older than this many days (defaults to the configured idempotency TTL)}';

    protected $description = 'Delete processed-event idempotency receipts older than the configured TTL';

    public function handle(Container $container): int
    {
        if (! $container->bound(ProcessedEventStore::class)) {
            $this->components->warn('Marketplace idempotency is disabled; nothing to prune.');

            return self::SUCCESS;
        }

        $days = $this->option('days') ?? config('marketplace-aws.persistence.idempotency.ttl');

        if ($days === null) {
            $this->components->warn('No idempotency TTL configured (marketplace-aws.persistence.idempotency.ttl); nothing pruned.');

            return self::SUCCESS;
        }

        if (! is_numeric($days) || (int) $days <= 0) {
            $this->components->error('The idempotency TTL must be a positive number of days.');

            return self::FAILURE;
        }

        $days = (int) $days;
        $deleted = $container->make(ProcessedEventStore::class)->prune(CarbonImmutable::now()->subDays($days));

        $this->components->info(sprintf('Pruned %d processed-event receipt(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
