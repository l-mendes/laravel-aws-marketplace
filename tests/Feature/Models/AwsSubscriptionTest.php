<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use LMendes\LaravelAwsMarketplace\Models\AwsSubscription;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AwsSubscriptionTest extends TestCase
{
    #[Test]
    public function it_exposes_a_polymorphic_owner_relation(): void
    {
        $this->assertInstanceOf(MorphTo::class, (new AwsSubscription)->owner());
    }

    #[Test]
    public function it_resolves_its_table_from_config(): void
    {
        $this->assertSame('aws_marketplace_subscriptions', (new AwsSubscription)->getTable());
    }
}
