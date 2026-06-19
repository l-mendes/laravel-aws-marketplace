<?php

namespace LMendes\LaravelAwsMarketplace\Enums;

/**
 * The normalized classification of an AWS Marketplace lifecycle event. AWS has no suspend/reinstate, so
 * those have no case; an amendment or license update is a single Updated transition (AWS does not split
 * plan vs quantity). Unknown covers events that carry no actionable transition (for example an agreement
 * ended because it was renewed or replaced, where the superseding agreement carries the real signal).
 */
enum EventType: string
{
    case Activated = 'activated';
    case Renewed = 'renewed';
    case Replaced = 'replaced';
    case Updated = 'updated';
    case Unsubscribed = 'unsubscribed';
    case Unknown = 'unknown';

    public function toSubscriptionStatus(): ?SubscriptionStatus
    {
        return match ($this) {
            self::Activated, self::Renewed, self::Replaced => SubscriptionStatus::Active,
            self::Unsubscribed => SubscriptionStatus::Unsubscribed,
            self::Updated, self::Unknown => null,
        };
    }
}
