<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

use Carbon\CarbonInterface;

/**
 * A buyer's entitlement for one pricing dimension of an AWS Marketplace contract (GetEntitlements). The
 * untouched AWS entitlement is kept in `raw`; mapping dimensions to an application plan or tier is the
 * consuming app's concern.
 */
final readonly class Entitlement
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $dimension,
        public int $units,
        public ?CarbonInterface $expiresAt = null,
        public array $raw = [],
    ) {}
}
