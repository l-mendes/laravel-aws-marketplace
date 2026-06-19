<?php

namespace LMendes\LaravelAwsMarketplace\Events;

use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;

/**
 * The catch-all event raised for every inbound webhook, including deliveries with no actionable
 * transition (EventType::Unknown). `subscription` is the persisted subscription the event applied to,
 * or null when there was nothing to persist (persistence disabled, or an event for a subscription never
 * seen).
 */
class AwsMarketplaceEventReceived
{
    public function __construct(
        public readonly AwsMarketplaceEvent $event,
        public readonly ?Subscription $subscription = null,
    ) {}
}
