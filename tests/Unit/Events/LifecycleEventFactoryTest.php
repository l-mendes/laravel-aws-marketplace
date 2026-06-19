<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;
use LMendes\LaravelAwsMarketplace\Enums\EventType;
use LMendes\LaravelAwsMarketplace\Events\LifecycleEventFactory;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionRenewed;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionReplaced;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionSuperseded;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LifecycleEventFactoryTest extends TestCase
{
    private LifecycleEventFactory $factory;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new LifecycleEventFactory;
        $this->subscription = new Subscription(id: 'agmt-1');
    }

    #[Test]
    public function it_builds_the_typed_event_carrying_the_subscription(): void
    {
        $cases = [
            [EventType::Activated, SubscriptionActivated::class],
            [EventType::Renewed, SubscriptionRenewed::class],
            [EventType::Replaced, SubscriptionReplaced::class],
            [EventType::Updated, SubscriptionUpdated::class],
            [EventType::Superseded, SubscriptionSuperseded::class],
            [EventType::Unsubscribed, SubscriptionCancelled::class],
        ];

        foreach ($cases as [$type, $class]) {
            $result = $this->factory->make(new AwsMarketplaceEvent(type: $type, detailType: 'x'), $this->subscription);

            $this->assertInstanceOf($class, $result, $type->value);
            $this->assertSame($this->subscription, $result->subscription);
        }
    }

    #[Test]
    public function it_returns_null_for_an_unknown_event(): void
    {
        $result = $this->factory->make(new AwsMarketplaceEvent(type: EventType::Unknown, detailType: 'x'), $this->subscription);

        $this->assertNull($result);
    }

    #[Test]
    public function it_carries_the_cancellation_reason_and_change_hint(): void
    {
        $cancelled = $this->factory->make(
            new AwsMarketplaceEvent(type: EventType::Unsubscribed, detailType: 'x', cancellationReason: CancellationReason::Expired),
            $this->subscription,
        );
        $updated = $this->factory->make(
            new AwsMarketplaceEvent(type: EventType::Updated, detailType: 'x', changes: ['entitlements']),
            $this->subscription,
        );

        $this->assertSame(CancellationReason::Expired, $cancelled->reason);
        $this->assertTrue($updated->changes->has('entitlements'));
    }

    #[Test]
    public function it_defaults_the_cancellation_reason_to_unknown(): void
    {
        $cancelled = $this->factory->make(
            new AwsMarketplaceEvent(type: EventType::Unsubscribed, detailType: 'x'),
            $this->subscription,
        );

        $this->assertSame(CancellationReason::Unknown, $cancelled->reason);
    }
}
