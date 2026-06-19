<?php

namespace LMendes\LaravelAwsMarketplace;

use LMendes\LaravelAwsMarketplace\DTO\Entitlement;
use LMendes\LaravelAwsMarketplace\DTO\MeterResult;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;
use LMendes\LaravelAwsMarketplace\Services\AwsEntitlementService;
use LMendes\LaravelAwsMarketplace\Services\AwsMeteringService;
use LMendes\LaravelAwsMarketplace\Services\AwsResolveCustomerService;

/**
 * The entry point for AWS Marketplace operations: resolve a registration token, fetch a buyer's
 * entitlements, and report metered usage. Methods take the primitive identifiers (LicenseArn, account
 * id, product code) so they work both straight after the landing resolve and from a persisted
 * subscription.
 */
class AwsMarketplace
{
    public function __construct(
        private readonly AwsResolveCustomerService $resolver,
        private readonly AwsEntitlementService $entitlementService,
        private readonly AwsMeteringService $meteringService,
    ) {}

    public function resolve(string $registrationToken): ResolvedCustomer
    {
        return $this->resolver->resolve($registrationToken);
    }

    /**
     * @return list<Entitlement>
     */
    public function entitlements(string $productCode, string $licenseArn): array
    {
        return $this->entitlementService->fetch($productCode, $licenseArn);
    }

    public function meter(string $licenseArn, string $customerAccountId, UsageRecord ...$records): MeterResult
    {
        return $this->meteringService->meter($licenseArn, $customerAccountId, ...$records);
    }
}
