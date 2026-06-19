<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Console;

use Carbon\CarbonImmutable;
use LMendes\LaravelAwsMarketplace\Models\AwsProcessedEvent;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PruneProcessedEventsCommandTest extends TestCase
{
    private function seedOldAndFresh(): void
    {
        $old = CarbonImmutable::now()->subDays(100)->toDateTimeString();
        $fresh = CarbonImmutable::now()->toDateTimeString();

        AwsProcessedEvent::query()->insert([
            ['event_key' => 'old', 'processed_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['event_key' => 'fresh', 'processed_at' => $fresh, 'created_at' => $fresh, 'updated_at' => $fresh],
        ]);
    }

    #[Test]
    public function it_prunes_receipts_older_than_the_days_option(): void
    {
        $this->seedOldAndFresh();

        $this->artisan('aws-marketplace:prune-events', ['--days' => '30'])->assertSuccessful();

        $this->assertDatabaseMissing('aws_marketplace_processed_events', ['event_key' => 'old']);
        $this->assertDatabaseHas('aws_marketplace_processed_events', ['event_key' => 'fresh']);
    }

    #[Test]
    public function it_uses_the_configured_ttl_when_no_option_is_given(): void
    {
        config()->set('marketplace-aws.persistence.idempotency.ttl', 30);
        $this->seedOldAndFresh();

        $this->artisan('aws-marketplace:prune-events')->assertSuccessful();

        $this->assertDatabaseMissing('aws_marketplace_processed_events', ['event_key' => 'old']);
        $this->assertDatabaseHas('aws_marketplace_processed_events', ['event_key' => 'fresh']);
    }

    #[Test]
    public function it_prunes_nothing_when_no_ttl_is_configured(): void
    {
        config()->set('marketplace-aws.persistence.idempotency.ttl', null);
        $this->seedOldAndFresh();

        $this->artisan('aws-marketplace:prune-events')->assertSuccessful();

        $this->assertDatabaseCount('aws_marketplace_processed_events', 2);
    }

    #[Test]
    public function it_rejects_a_non_positive_ttl(): void
    {
        $this->artisan('aws-marketplace:prune-events', ['--days' => '0'])->assertFailed();
    }
}
