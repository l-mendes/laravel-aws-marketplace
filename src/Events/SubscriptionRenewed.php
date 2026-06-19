<?php

namespace LMendes\LaravelAwsMarketplace\Events;

/**
 * An agreement was renewed (Purchase Agreement Created, intent RENEW). AWS mints a NEW agreement and
 * LicenseArn for the renewal with no pointer to the prior one, so `subscription` is a new lineage.
 * Reconcile it to the existing customer using your own buyer-account-to-tenant mapping; AWS exposes no
 * link to do it automatically.
 */
class SubscriptionRenewed extends SubscriptionLifecycleEvent {}
