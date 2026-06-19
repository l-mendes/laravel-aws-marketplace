<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

use Carbon\CarbonInterface;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;

/**
 * An AWS Marketplace subscription, one per agreement. `id` is the agreement id: the identifier present
 * on every lifecycle event (created, amended, ended, license updated, license deprovisioned), which is
 * why it is the stable key the consuming app keys on. `licenseArn` is the operational handle for
 * GetEntitlements and BatchMeterUsage; it is absent on the agreement events and gets filled in from the
 * License Updated event (or the landing resolve), so it may be null until then.
 *
 * Renewal and replacement mint a NEW agreement (new `id`) with no pointer back to the prior one: AWS
 * exposes no link, so this library never correlates across agreements. Binding a subscription to your
 * tenant/customer is the consuming app's responsibility; on a renewal you receive a new subscription
 * plus the buyer account id and reconcile it against your own mapping.
 */
final readonly class Subscription
{
    /**
     * @param  list<Entitlement>  $entitlements
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $licenseArn = null,
        public ?string $productCode = null,
        public ?string $customerAccountId = null,
        public ?string $customerIdentifier = null,
        public ?SubscriptionStatus $status = null,
        public ?CarbonInterface $currentPeriodEnd = null,
        public array $entitlements = [],
        public array $raw = [],
    ) {}
}
