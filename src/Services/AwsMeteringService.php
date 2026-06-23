<?php

namespace LMendes\LaravelAwsMarketplace\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use LMendes\LaravelAwsMarketplace\DTO\MeteredRecord;
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

        $accepted = [];
        $rejected = [];
        $duplicates = [];

        foreach ($result['Results'] ?? [] as $item) {
            $record = $this->toRecord($item['UsageRecord'] ?? [], $item, $item['MeteringRecordId'] ?? null);

            match ($item['Status'] ?? null) {
                'Success' => $accepted[] = $record,
                'DuplicateRecord' => $duplicates[] = $record,
                default => $rejected[] = $record,
            };
        }

        $unprocessed = array_values(array_map(
            fn (array $item): MeteredRecord => $this->toRecord($item, $item, null),
            $result['UnprocessedRecords'] ?? [],
        ));

        return new MeterResult(
            accepted: $accepted,
            rejected: $rejected,
            duplicates: $duplicates,
            unprocessed: $unprocessed,
            raw: $result->toArray(),
        );
    }

    /**
     * @param  array<string, mixed>  $usage  The UsageRecord fields AWS echoes back.
     * @param  array<string, mixed>  $raw  The untouched AWS entry this record was mapped from.
     */
    private function toRecord(array $usage, array $raw, ?string $meteringRecordId): MeteredRecord
    {
        return new MeteredRecord(
            dimension: $usage['Dimension'] ?? '',
            quantity: (int) ($usage['Quantity'] ?? 0),
            customerAccountId: $usage['CustomerAWSAccountId'] ?? null,
            meteringRecordId: $meteringRecordId,
            timestamp: $this->toTimestamp($usage['Timestamp'] ?? null),
            raw: $raw,
        );
    }

    private function toTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value);
        }

        return null;
    }
}
