<?php

namespace LMendes\LaravelAwsMarketplace\Services;

use Aws\MarketplaceEntitlementService\MarketplaceEntitlementServiceClient;
use Illuminate\Support\Carbon;
use LMendes\LaravelAwsMarketplace\DTO\Entitlement;

/**
 * Fetches a buyer's AWS Marketplace contract entitlements for a license (GetEntitlements), following
 * pagination. The raw entitlement is preserved on each Entitlement; interpreting them into a contracted
 * plan or tier is the caller's concern.
 */
class AwsEntitlementService
{
    public function __construct(
        private readonly MarketplaceEntitlementServiceClient $client,
    ) {}

    /**
     * @return list<Entitlement>
     */
    public function fetch(string $productCode, string $licenseArn): array
    {
        $entitlements = [];
        $nextToken = null;

        do {
            $request = [
                'ProductCode' => $productCode,
                'Filter' => ['LICENSE_ARN' => [$licenseArn]],
            ];

            if ($nextToken !== null) {
                $request['NextToken'] = $nextToken;
            }

            $result = $this->client->getEntitlements($request);

            foreach ($result['Entitlements'] ?? [] as $entitlement) {
                $entitlements[] = $this->toData($entitlement);
            }

            $nextToken = $result['NextToken'] ?? null;
        } while (! empty($nextToken));

        return $entitlements;
    }

    /**
     * @param  array<string, mixed>  $entitlement
     */
    private function toData(array $entitlement): Entitlement
    {
        $expiresAt = $entitlement['ExpirationDate'] ?? null;

        return new Entitlement(
            dimension: $entitlement['Dimension'],
            units: (int) ($entitlement['Value']['IntegerValue'] ?? 0),
            expiresAt: $expiresAt !== null ? Carbon::instance($expiresAt) : null,
            raw: $entitlement,
        );
    }
}
