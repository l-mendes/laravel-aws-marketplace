<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

use Carbon\CarbonInterface;

/**
 * A single usage record as it came back from AWS Marketplace (BatchMeterUsage), normalized into one shape
 * whatever bucket of MeterResult it lands in. AWS returns two different shapes (a UsageRecordResult inside
 * `Results`, a bare UsageRecord inside `UnprocessedRecords`); both map here. The record's disposition is
 * the MeterResult bucket it belongs to, so it is not repeated as a field; the untouched AWS entry is kept
 * in `raw`. `meteringRecordId` is null for records AWS never processed (the unprocessed bucket).
 */
final readonly class MeteredRecord
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $dimension,
        public int $quantity,
        public ?string $customerAccountId = null,
        public ?string $meteringRecordId = null,
        public ?CarbonInterface $timestamp = null,
        public array $raw = [],
    ) {}
}
