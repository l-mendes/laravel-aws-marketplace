<?php

namespace LMendes\LaravelAwsMarketplace\Enums;

/**
 * The lifecycle status of an AWS Marketplace subscription. PendingFulfillment is the state right after
 * resolving the registration token, before the buyer's entitlements are confirmed; Active once the
 * agreement is in force; Superseded once the agreement ends because it was renewed or replaced (access
 * continues on the successor agreement, so this is not a revocation); Unsubscribed once it ends for real
 * (cancelled, expired, or terminated). AWS has no suspended state.
 */
enum SubscriptionStatus: string
{
    case PendingFulfillment = 'pending_fulfillment';
    case Active = 'active';
    case Superseded = 'superseded';
    case Unsubscribed = 'unsubscribed';
}
