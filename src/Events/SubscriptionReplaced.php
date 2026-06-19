<?php

namespace LMendes\LaravelAwsMarketplace\Events;

/**
 * An agreement was replaced by an agreement-based replacement offer (Purchase Agreement Created, intent
 * REPLACE). Like a renewal, this mints a NEW agreement and LicenseArn with no pointer back to the
 * replaced one, so `subscription` is a new lineage to reconcile against your own mapping.
 */
class SubscriptionReplaced extends SubscriptionLifecycleEvent {}
