<?php

namespace LMendes\LaravelAwsMarketplace\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;

/**
 * An agreement ended for real (Purchase Agreement Ended with status CANCELLED, EXPIRED, or TERMINATED,
 * or a License Deprovisioned). `reason` is the normalized cause. An end with status RENEWED or REPLACED
 * is NOT a cancellation (the superseding agreement carries the signal) and does not raise this event.
 */
class SubscriptionCancelled extends SubscriptionLifecycleEvent
{
    public function __construct(
        Subscription $subscription,
        AwsMarketplaceEvent $event,
        public readonly CancellationReason $reason,
    ) {
        parent::__construct($subscription, $event);
    }
}
