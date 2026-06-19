<?php

namespace LMendes\LaravelAwsMarketplace\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;

/**
 * Exchanges an AWS Marketplace registration token for the buyer and license it identifies
 * (ResolveCustomer). The response carries the LicenseArn, CustomerAWSAccountId, CustomerIdentifier, and
 * ProductCode, but no agreement id, so this returns a ResolvedCustomer rather than a Subscription.
 */
class AwsResolveCustomerService
{
    public function __construct(
        private readonly MarketplaceMeteringClient $client,
    ) {}

    public function resolve(string $registrationToken): ResolvedCustomer
    {
        $result = $this->client->resolveCustomer([
            'RegistrationToken' => $registrationToken,
        ]);

        return new ResolvedCustomer(
            licenseArn: (string) ($result['LicenseArn'] ?? ''),
            customerAccountId: $result['CustomerAWSAccountId'] ?? null,
            customerIdentifier: $result['CustomerIdentifier'] ?? null,
            productCode: $result['ProductCode'] ?? null,
            raw: [
                'CustomerIdentifier' => $result['CustomerIdentifier'] ?? null,
                'CustomerAWSAccountId' => $result['CustomerAWSAccountId'] ?? null,
                'ProductCode' => $result['ProductCode'] ?? null,
                'LicenseArn' => $result['LicenseArn'] ?? null,
            ],
        );
    }
}
