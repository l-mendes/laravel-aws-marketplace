<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

use Carbon\CarbonInterface;

/**
 * A single metered-usage record to report to AWS Marketplace: how much of a pricing dimension was
 * consumed, optionally at a specific time (defaults to now when metered).
 */
final readonly class UsageRecord
{
    public function __construct(
        public string $dimension,
        public int $quantity,
        public ?CarbonInterface $timestamp = null,
    ) {}
}
