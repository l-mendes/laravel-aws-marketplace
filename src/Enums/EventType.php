<?php

namespace LMendes\LaravelAwsMarketplace\Enums;

/**
 * The normalized classification of an AWS Marketplace lifecycle event. AWS has no suspend/reinstate, so
 * those have no case; an amendment or license update is a single Updated transition (AWS does not split
 * plan vs quantity). Superseded is an agreement that ended because it was renewed or replaced: not a real
 * termination, since access continues on the successor agreement. Unknown covers events that carry no
 * actionable transition or are unrecognized.
 */
enum EventType: string
{
    case Activated = 'activated';
    case Renewed = 'renewed';
    case Replaced = 'replaced';
    case Updated = 'updated';
    case Superseded = 'superseded';
    case Unsubscribed = 'unsubscribed';
    case Unknown = 'unknown';

    public function toSubscriptionStatus(): ?SubscriptionStatus
    {
        return match ($this) {
            self::Activated, self::Renewed, self::Replaced => SubscriptionStatus::Active,
            self::Superseded => SubscriptionStatus::Superseded,
            self::Unsubscribed => SubscriptionStatus::Unsubscribed,
            self::Updated, self::Unknown => null,
        };
    }
}
