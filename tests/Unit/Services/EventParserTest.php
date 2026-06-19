<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Services;

use Carbon\CarbonImmutable;
use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;
use LMendes\LaravelAwsMarketplace\Enums\CancellationReason;
use LMendes\LaravelAwsMarketplace\Enums\EventType;
use LMendes\LaravelAwsMarketplace\Services\EventParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EventParserTest extends TestCase
{
    private EventParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EventParser;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function payload(string $detailType, array $detail = [], ?string $envelopeId = null): array
    {
        $payload = [
            'detail-type' => $detailType,
            'source' => 'aws.agreement-marketplace',
            'detail' => $detail,
        ];

        if ($envelopeId !== null) {
            $payload['id'] = $envelopeId;
        }

        return $payload;
    }

    #[Test]
    public function it_maps_agreement_and_license_events_by_intent_and_status(): void
    {
        $cases = [
            ['Purchase Agreement Created - Proposer', ['agreement' => ['intent' => 'NEW']], EventType::Activated],
            ['Purchase Agreement Created - Manufacturer', ['agreement' => ['intent' => 'NEW']], EventType::Activated],
            ['Purchase Agreement Created - Proposer', ['agreement' => ['intent' => 'RENEW']], EventType::Renewed],
            ['Purchase Agreement Created - Proposer', ['agreement' => ['intent' => 'REPLACE']], EventType::Replaced],
            ['Purchase Agreement Amended - Proposer', ['agreement' => ['intent' => 'AMEND']], EventType::Updated],
            ['License Updated - Manufacturer', [], EventType::Updated],
            ['Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'CANCELLED']], EventType::Unsubscribed],
            ['Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'EXPIRED']], EventType::Unsubscribed],
            ['Purchase Agreement Ended - Manufacturer', ['agreement' => ['status' => 'TERMINATED']], EventType::Unsubscribed],
            ['Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'RENEWED']], EventType::Unknown],
            ['Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'REPLACED']], EventType::Unknown],
            ['License Deprovisioned - Manufacturer', [], EventType::Unsubscribed],
            ['Something Else', [], EventType::Unknown],
        ];

        foreach ($cases as [$detailType, $detail, $expected]) {
            $event = $this->parser->parse($this->payload($detailType, $detail));

            $this->assertSame($expected, $event->type, $detailType);
            $this->assertSame($detailType, $event->detailType, $detailType);
        }
    }

    #[Test]
    public function it_extracts_the_native_aws_fields(): void
    {
        $event = $this->parser->parse($this->payload('License Updated - Manufacturer', [
            'requestId' => 'req-1',
            'agreement' => ['id' => 'agmt-1'],
            'license' => ['arn' => 'arn-1'],
            'acceptor' => ['accountId' => 'acct-1'],
            'product' => ['code' => 'prod-x'],
        ]));

        $this->assertSame('arn-1', $event->licenseArn);
        $this->assertSame('agmt-1', $event->agreementId);
        $this->assertSame('acct-1', $event->customerAccountId);
        $this->assertSame('prod-x', $event->productCode);
        $this->assertSame('req-1', $event->idempotencyKey);
    }

    #[Test]
    public function it_exposes_the_intent_and_agreement_status_natively(): void
    {
        $created = $this->parser->parse($this->payload('Purchase Agreement Created - Proposer', ['agreement' => ['id' => 'agmt-1', 'intent' => 'RENEW']]));
        $ended = $this->parser->parse($this->payload('Purchase Agreement Ended - Proposer', ['agreement' => ['id' => 'agmt-1', 'status' => 'CANCELLED']]));

        $this->assertSame('RENEW', $created->intent);
        $this->assertNull($created->agreementStatus);
        $this->assertSame('CANCELLED', $ended->agreementStatus);
        $this->assertNull($ended->intent);
    }

    #[Test]
    public function it_leaves_the_license_arn_null_on_agreement_only_events(): void
    {
        $event = $this->parser->parse($this->payload('Purchase Agreement Created - Proposer', ['agreement' => ['id' => 'agmt-1', 'intent' => 'NEW']]));

        $this->assertNull($event->licenseArn);
        $this->assertSame('agmt-1', $event->agreementId);
    }

    #[Test]
    public function it_falls_back_to_the_envelope_id_then_null_for_the_idempotency_key(): void
    {
        $withEnvelope = $this->parser->parse($this->payload('License Updated - Manufacturer', ['license' => ['arn' => 'arn-1']], 'env-1'));
        $without = $this->parser->parse($this->payload('License Updated - Manufacturer', ['license' => ['arn' => 'arn-1']]));

        $this->assertSame('env-1', $withEnvelope->idempotencyKey);
        $this->assertNull($without->idempotencyKey);
    }

    #[Test]
    public function it_derives_the_cancellation_reason_only_for_terminations(): void
    {
        $cancelled = $this->parser->parse($this->payload('Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'CANCELLED']]));
        $expired = $this->parser->parse($this->payload('Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'EXPIRED']]));
        $terminated = $this->parser->parse($this->payload('Purchase Agreement Ended - Proposer', ['agreement' => ['status' => 'TERMINATED']]));
        $deprovisioned = $this->parser->parse($this->payload('License Deprovisioned - Manufacturer', []));
        $activated = $this->parser->parse($this->payload('Purchase Agreement Created - Proposer', ['agreement' => ['intent' => 'NEW']]));

        $this->assertSame(CancellationReason::Cancelled, $cancelled->cancellationReason);
        $this->assertSame(CancellationReason::Expired, $expired->cancellationReason);
        $this->assertSame(CancellationReason::Terminated, $terminated->cancellationReason);
        $this->assertSame(CancellationReason::Unknown, $deprovisioned->cancellationReason);
        $this->assertNull($activated->cancellationReason);
    }

    #[Test]
    public function it_flags_entitlement_changes_only_on_amended_and_license_updated(): void
    {
        $licenseUpdated = $this->parser->parse($this->payload('License Updated - Manufacturer', []));
        $amended = $this->parser->parse($this->payload('Purchase Agreement Amended - Proposer', ['agreement' => ['intent' => 'AMEND']]));
        $created = $this->parser->parse($this->payload('Purchase Agreement Created - Proposer', ['agreement' => ['intent' => 'NEW']]));

        $this->assertSame([SubscriptionChanges::ENTITLEMENTS], $licenseUpdated->changes);
        $this->assertSame([SubscriptionChanges::ENTITLEMENTS], $amended->changes);
        $this->assertSame([], $created->changes);
    }

    #[Test]
    public function it_sets_the_final_metering_deadline_one_hour_after_the_agreement_end_on_termination(): void
    {
        $event = $this->parser->parse($this->payload('Purchase Agreement Ended - Proposer', [
            'agreement' => ['status' => 'CANCELLED', 'endTime' => '2026-09-01T00:00:00Z'],
        ]));

        $this->assertNotNull($event->finalMeteringDeadline);
        $this->assertTrue(CarbonImmutable::parse('2026-09-01T01:00:00Z')->equalTo($event->finalMeteringDeadline));
        $this->assertTrue(CarbonImmutable::parse('2026-09-01T00:00:00Z')->equalTo($event->currentPeriodEnd));
    }

    #[Test]
    public function it_leaves_the_final_metering_deadline_null_for_non_terminations(): void
    {
        $event = $this->parser->parse($this->payload('Purchase Agreement Created - Proposer', [
            'agreement' => ['intent' => 'NEW', 'endTime' => '2026-09-01T00:00:00Z'],
        ]));

        $this->assertNull($event->finalMeteringDeadline);
    }

    #[Test]
    public function it_preserves_the_raw_payload(): void
    {
        $payload = $this->payload('License Updated - Manufacturer', ['license' => ['arn' => 'arn-1']]);

        $event = $this->parser->parse($payload);

        $this->assertSame($payload, $event->raw);
    }
}
