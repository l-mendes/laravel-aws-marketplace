<?php

namespace LMendes\LaravelAwsMarketplace\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;

/**
 * An in-force agreement changed (Purchase Agreement Amended or License Updated) on the SAME LicenseArn,
 * so `subscription` keeps its id. `changes` hints what moved; AWS does not enumerate it precisely, so
 * the safe handler re-fetches entitlements.
 */
class SubscriptionUpdated extends SubscriptionLifecycleEvent
{
    public function __construct(
        Subscription $subscription,
        AwsMarketplaceEvent $event,
        public readonly SubscriptionChanges $changes,
    ) {
        parent::__construct($subscription, $event);
    }
}
