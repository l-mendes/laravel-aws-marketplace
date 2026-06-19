<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

/**
 * The outcome of reporting metered usage to AWS Marketplace (BatchMeterUsage): the records AWS accepted
 * and the ones it could not process, plus the untouched API response in `raw`.
 */
final readonly class MeterResult
{
    /**
     * @param  list<array<string, mixed>>  $accepted
     * @param  list<array<string, mixed>>  $rejected
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
        public array $raw = [],
    ) {}
}
