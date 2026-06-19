<?php

namespace LMendes\LaravelAwsMarketplace\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;

/**
 * Base for the typed AWS Marketplace lifecycle events. Each carries the affected `subscription` and the
 * normalized `event` it came from (which preserves the native AWS fields and the raw payload), so a
 * listener has both the domain shape and full access to the original delivery.
 */
abstract class SubscriptionLifecycleEvent
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly AwsMarketplaceEvent $event,
    ) {}
}
