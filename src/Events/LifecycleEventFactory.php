<?php

namespace LMendes\LaravelAwsMarketplace\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;
use LMendes\LaravelAwsMarketplace\Enums\EventType;

/**
 * Resolves the specific lifecycle event for a normalized AwsMarketplaceEvent, carrying the subscription
 * it applies to. Returns null for events with no actionable transition (EventType::Unknown), which are
 * surfaced only through the generic AwsMarketplaceEventReceived.
 */
class LifecycleEventFactory
{
    public function make(AwsMarketplaceEvent $event, Subscription $subscription): ?SubscriptionLifecycleEvent
    {
        return match ($event->type) {
            EventType::Activated => new SubscriptionActivated($subscription, $event),
            EventType::Renewed => new SubscriptionRenewed($subscription, $event),
            EventType::Replaced => new SubscriptionReplaced($subscription, $event),
            EventType::Updated => new SubscriptionUpdated($subscription, $event, new SubscriptionChanges($event->changes)),
            EventType::Unsubscribed => new SubscriptionCancelled($subscription, $event, $event->cancellationReason ?? CancellationReason::Unknown),
            EventType::Unknown => null,
        };
    }
}
