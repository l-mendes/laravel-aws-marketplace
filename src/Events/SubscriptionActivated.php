<?php

namespace LMendes\LaravelAwsMarketplace\Events;

/**
 * A buyer accepted a new agreement (Purchase Agreement Created, intent NEW). The subscription is its own
 * fresh lineage; bind it to your tenant here.
 */
class SubscriptionActivated extends SubscriptionLifecycleEvent {}
