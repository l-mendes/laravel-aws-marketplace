<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

/**
 * The result of resolving an AWS Marketplace registration token at the landing step (ResolveCustomer).
 * It identifies the buyer and the granted license, not an agreement: ResolveCustomer returns the
 * `licenseArn`, `customerAccountId` (CustomerAWSAccountId), `customerIdentifier` (CustomerIdentifier),
 * and `productCode`, but no agreement id. Bind your tenant to the buyer here; the canonical
 * subscription (keyed by agreement id) is maintained from the lifecycle events and joins back to this
 * customer through the `licenseArn`, which the License Updated event carries alongside the agreement id.
 */
final readonly class ResolvedCustomer
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $licenseArn,
        public ?string $customerAccountId = null,
        public ?string $customerIdentifier = null,
        public ?string $productCode = null,
        public array $raw = [],
    ) {}
}
