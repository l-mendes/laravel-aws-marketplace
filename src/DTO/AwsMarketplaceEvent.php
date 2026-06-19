<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

use Carbon\CarbonInterface;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;
use LMendes\LaravelAwsMarketplace\Enums\EventType;

/**
 * A normalized AWS Marketplace lifecycle event parsed from an EventBridge delivery. `type` is the domain
 * classification; the native AWS values are preserved next to it (`detailType`, `intent`,
 * `agreementStatus`, `licenseArn`, `agreementId`, `customerAccountId`, `productCode`) along with the
 * untouched `raw` payload, so a handler can read either the normalized shape or the exact AWS fields.
 *
 * `idempotencyKey` is detail.requestId (falling back to the EventBridge envelope id), used to deduplicate
 * retried deliveries. `currentPeriodEnd` and `finalMeteringDeadline` are parsed from the agreement end
 * time when present; `cancellationReason` is set only on a termination; `changes` hints what an Updated
 * event touched. Agreement-only events (amended, ended) carry `agreementId` but no `licenseArn`.
 */
final readonly class AwsMarketplaceEvent
{
    /**
     * @param  list<string>  $changes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public EventType $type,
        public string $detailType,
        public ?string $licenseArn = null,
        public ?string $agreementId = null,
        public ?string $customerAccountId = null,
        public ?string $productCode = null,
        public ?string $intent = null,
        public ?string $agreementStatus = null,
        public ?string $idempotencyKey = null,
        public ?CarbonInterface $currentPeriodEnd = null,
        public ?CarbonInterface $finalMeteringDeadline = null,
        public ?CancellationReason $cancellationReason = null,
        public array $changes = [],
        public array $raw = [],
    ) {}
}
