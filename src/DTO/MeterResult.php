<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

/**
 * The outcome of reporting metered usage to AWS Marketplace (BatchMeterUsage), normalized by the
 * per-record `Status` AWS returns inside `Results` plus the separate `UnprocessedRecords` list, with the
 * untouched API response kept in `raw`.
 */
final readonly class MeterResult
{
    /**
     * @param  list<MeteredRecord>  $accepted  Results AWS counted (Status `Success`).
     * @param  list<MeteredRecord>  $rejected  Results AWS dropped permanently (Status `CustomerNotSubscribed` or any unrecognized status); retrying will not help.
     * @param  list<MeteredRecord>  $duplicates  Results AWS had already counted for the window (Status `DuplicateRecord`); idempotent no-ops, not failures.
     * @param  list<MeteredRecord>  $unprocessed  Records AWS could not process due to a transient error (UnprocessedRecords); these should be retried.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
        public array $duplicates = [],
        public array $unprocessed = [],
        public array $raw = [],
    ) {}
}
