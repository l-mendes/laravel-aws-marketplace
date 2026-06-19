<?php

namespace LMendes\LaravelAwsMarketplace\Contracts;

use LMendes\LaravelAwsMarketplace\DTO\Subscription;

/**
 * Persists and retrieves AWS Marketplace subscriptions. The package ships an Eloquent-backed
 * implementation; bind your own to integrate with an existing schema. Linking a subscription to your
 * user/tenant is done through the persisted model's polymorphic owner, not here.
 */
interface SubscriptionRepository
{
    /**
     * Find a subscription by its agreement id (the canonical key).
     */
    public function find(string $agreementId): ?Subscription;

    /**
     * Find the subscription currently carrying the given LicenseArn, or null. Lets the consuming app
     * connect a tenant bound at the landing step (which knows only the LicenseArn) to the canonical
     * subscription once a License event has linked that LicenseArn to its agreement.
     */
    public function findByLicenseArn(string $licenseArn): ?Subscription;

    /**
     * Insert or update the subscription, keyed by its agreement id. Must not clobber an existing owner.
     */
    public function save(Subscription $subscription): Subscription;
}
