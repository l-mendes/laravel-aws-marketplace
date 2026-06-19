<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use LMendes\LaravelAwsMarketplace\Models\AwsProcessedEvent;
use LMendes\LaravelAwsMarketplace\Repositories\EloquentProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EloquentProcessedEventStoreTest extends TestCase
{
    private function store(): EloquentProcessedEventStore
    {
        return new EloquentProcessedEventStore(AwsProcessedEvent::class);
    }

    #[Test]
    public function an_unseen_key_is_not_processed(): void
    {
        $this->assertFalse($this->store()->isProcessed('req-1'));
    }

    #[Test]
    public function marking_a_key_makes_it_processed_and_is_idempotent(): void
    {
        $store = $this->store();

        $store->markProcessed('req-1');
        $store->markProcessed('req-1');

        $this->assertTrue($store->isProcessed('req-1'));
        $this->assertDatabaseCount('aws_marketplace_processed_events', 1);
        $this->assertNotNull(AwsProcessedEvent::query()->first()->processed_at);
    }

    #[Test]
    public function it_prunes_receipts_created_before_the_cutoff(): void
    {
        $old = CarbonImmutable::now()->subDays(100)->toDateTimeString();
        $fresh = CarbonImmutable::now()->toDateTimeString();

        AwsProcessedEvent::query()->insert([
            ['event_key' => 'old', 'processed_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['event_key' => 'fresh', 'processed_at' => $fresh, 'created_at' => $fresh, 'updated_at' => $fresh],
        ]);

        $deleted = $this->store()->prune(CarbonImmutable::now()->subDays(30));

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('aws_marketplace_processed_events', ['event_key' => 'old']);
        $this->assertDatabaseHas('aws_marketplace_processed_events', ['event_key' => 'fresh']);
    }
}
