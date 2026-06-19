<?php

namespace LMendes\LaravelAwsMarketplace\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Carbon\CarbonImmutable;
use LMendes\LaravelAwsMarketplace\DTO\MeterResult;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;

/**
 * Reports metered-usage records to AWS Marketplace (BatchMeterUsage). Each record is identified by the
 * buyer's AWS account id plus the agreement LicenseArn (the Concurrent Agreements model), so AWS
 * de-duplicates identical records and resending the same record for a window is safe even when one buyer
 * holds concurrent agreements. Takes the LicenseArn and account id directly so usage can be reported
 * straight after the landing resolve (before any subscription row exists) as well as from a persisted
 * subscription.
 */
class AwsMeteringService
{
    public function __construct(
        private readonly MarketplaceMeteringClient $client,
    ) {}

    public function meter(string $licenseArn, string $customerAccountId, UsageRecord ...$records): MeterResult
    {
        $usageRecords = array_map(fn (UsageRecord $record): array => [
            'Timestamp' => ($record->timestamp ?? CarbonImmutable::now())->getTimestamp(),
            'CustomerAWSAccountId' => $customerAccountId,
            'LicenseArn' => $licenseArn,
            'Dimension' => $record->dimension,
            'Quantity' => $record->quantity,
        ], $records);

        $result = $this->client->batchMeterUsage([
            'UsageRecords' => $usageRecords,
        ]);

        return new MeterResult(
            accepted: $result['Results'] ?? [],
            rejected: $result['UnprocessedRecords'] ?? [],
            raw: $result->toArray(),
        );
    }
}
