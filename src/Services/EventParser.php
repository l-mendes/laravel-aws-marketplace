<?php

namespace LMendes\LaravelAwsMarketplace\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Arr;
use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;
use LMendes\LaravelAwsMarketplace\Enums\EventType;

/**
 * Parses an AWS Marketplace EventBridge delivery (source aws.agreement-marketplace) into a normalized
 * AwsMarketplaceEvent. All SaaS events carry the agreement id; only the license events (License Updated,
 * License Deprovisioned) also carry the LicenseArn, and only the agreement-created event carries the
 * intent, so the normalized event preserves every native field next to the classified type.
 */
class EventParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): AwsMarketplaceEvent
    {
        $detailType = (string) Arr::get($payload, 'detail-type', '');
        $intent = Arr::get($payload, 'detail.agreement.intent');
        $status = Arr::get($payload, 'detail.agreement.status');
        $type = $this->mapType($this->withoutRole($detailType), $intent, $status);
        $currentPeriodEnd = $this->parsePeriodEnd(Arr::get($payload, 'detail.agreement.endTime'));

        return new AwsMarketplaceEvent(
            type: $type,
            detailType: $detailType,
            licenseArn: $this->stringOrNull(Arr::get($payload, 'detail.license.arn')),
            agreementId: $this->stringOrNull(Arr::get($payload, 'detail.agreement.id')),
            customerAccountId: $this->stringOrNull(Arr::get($payload, 'detail.acceptor.accountId')),
            productCode: $this->stringOrNull(Arr::get($payload, 'detail.product.code')),
            intent: $this->stringOrNull($intent),
            agreementStatus: $this->stringOrNull($status),
            idempotencyKey: $this->idempotencyKey($payload),
            currentPeriodEnd: $currentPeriodEnd,
            finalMeteringDeadline: $this->finalMeteringDeadline($type, $currentPeriodEnd),
            cancellationReason: $type === EventType::Unsubscribed ? $this->mapCancellationReason($status) : null,
            changes: $this->mapChanges($this->withoutRole($detailType)),
            raw: $payload,
        );
    }

    /**
     * Map a role-stripped detail-type, agreement intent, and ended status onto a normalized event type.
     * Purchase Agreement Created carries the intent (NEW first purchase, RENEW auto-renewal, REPLACE
     * agreement-based replacement). License Updated and an amendment both re-provision entitlements, so
     * both are Updated. An agreement ended with status RENEWED or REPLACED is not a real termination (the
     * superseding agreement carries the signal), so it maps to Unknown to avoid revoking access.
     */
    private function mapType(string $detailType, ?string $intent, ?string $status): EventType
    {
        return match ($detailType) {
            'Purchase Agreement Created' => match ($intent) {
                'RENEW' => EventType::Renewed,
                'REPLACE' => EventType::Replaced,
                default => EventType::Activated,
            },
            'Purchase Agreement Amended', 'License Updated' => EventType::Updated,
            'Purchase Agreement Ended' => match ($status) {
                'RENEWED', 'REPLACED' => EventType::Unknown,
                default => EventType::Unsubscribed,
            },
            'License Deprovisioned' => EventType::Unsubscribed,
            default => EventType::Unknown,
        };
    }

    /**
     * AWS Marketplace agreement and license events carry a role suffix: " - Proposer" for the seller of
     * record and " - Manufacturer" for the ISV. The license events are Manufacturer-only; a seller that
     * is both receives the Proposer variant of the agreement events.
     */
    private function withoutRole(string $detailType): string
    {
        foreach ([' - Proposer', ' - Manufacturer'] as $suffix) {
            if (str_ends_with($detailType, $suffix)) {
                return substr($detailType, 0, -strlen($suffix));
            }
        }

        return $detailType;
    }

    /**
     * AWS does not enumerate which attributes changed. An amendment and a license update both
     * re-provision the buyer's entitlements, so they are reported as an entitlement change; every other
     * event carries no diff hint.
     *
     * @return list<string>
     */
    private function mapChanges(string $detailType): array
    {
        return match ($detailType) {
            'Purchase Agreement Amended', 'License Updated' => [SubscriptionChanges::ENTITLEMENTS],
            default => [],
        };
    }

    private function mapCancellationReason(?string $status): CancellationReason
    {
        return match ($status) {
            'CANCELLED' => CancellationReason::Cancelled,
            'EXPIRED' => CancellationReason::Expired,
            'TERMINATED' => CancellationReason::Terminated,
            default => CancellationReason::Unknown,
        };
    }

    /**
     * Parse an agreement end time (ISO-8601) into the current period end. A missing, empty, or malformed
     * value yields null rather than aborting the webhook, since a 500 would have EventBridge retry the
     * delivery indefinitely.
     */
    private function parsePeriodEnd(mixed $endTime): ?CarbonInterface
    {
        if (! is_string($endTime) || $endTime === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($endTime);
        } catch (InvalidFormatException $exception) {
            return null;
        }
    }

    /**
     * AWS allows a final metered-usage submission within one hour after an agreement terminates. The
     * deadline is surfaced uniformly (null when it does not apply), anchored on the agreement end time and
     * falling back to the receipt time when the termination carries none (for example a deprovisioned
     * license).
     */
    private function finalMeteringDeadline(EventType $type, ?CarbonInterface $currentPeriodEnd): ?CarbonInterface
    {
        if ($type !== EventType::Unsubscribed) {
            return null;
        }

        return ($currentPeriodEnd ?? CarbonImmutable::now())->addHour();
    }

    /**
     * The dedup key the webhook pipeline keys retries on: the action's requestId, falling back to the
     * EventBridge envelope id. Null (no usable key) leaves the event un-deduped.
     *
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyKey(array $payload): ?string
    {
        return $this->stringOrNull(Arr::get($payload, 'detail.requestId') ?? Arr::get($payload, 'id'));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
