<?php

namespace LMendes\LaravelAwsMarketplace\Events;

/**
 * An agreement ended because it was renewed or replaced (Purchase Agreement Ended with status RENEWED or
 * REPLACED). This is NOT a cancellation: do not revoke access. The subscription is marked superseded, and
 * the successor arrives as SubscriptionRenewed or SubscriptionReplaced on the new agreement, which you
 * reconcile to the same customer. Read `$event->agreementStatus` for RENEWED vs REPLACED.
 */
class SubscriptionSuperseded extends SubscriptionLifecycleEvent {}
