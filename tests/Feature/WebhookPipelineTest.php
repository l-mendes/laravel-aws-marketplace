<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use Illuminate\Support\Facades\Event;
use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;
use LMendes\LaravelAwsMarketplace\Events\AwsMarketplaceEventReceived;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionRenewed;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionSuperseded;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class WebhookPipelineTest extends FeatureTestCase
{
    private function landed(string $agreementId = 'agmt-1', ?string $licenseArn = null): void
    {
        $this->app->make(SubscriptionRepository::class)->save(new Subscription(
            id: $agreementId,
            licenseArn: $licenseArn,
            productCode: 'prod-x',
            customerAccountId: 'acct-1',
            status: SubscriptionStatus::Active,
        ));
    }

    #[Test]
    public function a_new_agreement_persists_a_subscription_keyed_by_its_agreement_id(): void
    {
        Event::fake([SubscriptionActivated::class, AwsMarketplaceEventReceived::class]);

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Created - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-new',
                'agreement' => ['id' => 'agmt-1', 'intent' => 'NEW'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionActivated::class, fn ($e) => $e->subscription->id === 'agmt-1');
        Event::assertDispatched(AwsMarketplaceEventReceived::class, fn ($e) => $e->subscription?->id === 'agmt-1');
        $this->assertDatabaseHas('aws_marketplace_subscriptions', [
            'agreement_id' => 'agmt-1',
            'license_arn' => null,
            'customer_account_id' => 'acct-1',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function a_license_updated_fills_the_license_arn_on_the_same_subscription(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $this->landed('agmt-1');

        $this->postWebhook([
            'detail-type' => 'License Updated - Manufacturer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-license',
                'agreement' => ['id' => 'agmt-1'],
                'license' => ['arn' => 'arn-1'],
                'product' => ['code' => 'prod-x'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionUpdated::class, fn ($e) => $e->subscription->id === 'agmt-1' && $e->changes->has('entitlements'));
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 1);
        $this->assertDatabaseHas('aws_marketplace_subscriptions', [
            'agreement_id' => 'agmt-1',
            'license_arn' => 'arn-1',
        ]);
    }

    #[Test]
    public function a_renewal_is_a_distinct_subscription_with_its_own_agreement_id(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $this->landed('agmt-1', 'arn-1');

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Created - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-renew',
                'agreement' => ['id' => 'agmt-2', 'intent' => 'RENEW'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionRenewed::class, fn ($e) => $e->subscription->id === 'agmt-2');
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 2);
        $this->assertDatabaseHas('aws_marketplace_subscriptions', ['agreement_id' => 'agmt-1', 'license_arn' => 'arn-1']);
        $this->assertDatabaseHas('aws_marketplace_subscriptions', ['agreement_id' => 'agmt-2', 'status' => 'active']);
    }

    #[Test]
    public function an_ended_event_cancels_the_subscription(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $this->landed('agmt-1', 'arn-1');

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Ended - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-end',
                'agreement' => ['id' => 'agmt-1', 'status' => 'CANCELLED'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionCancelled::class, fn ($e) => $e->subscription->id === 'agmt-1' && $e->reason->value === 'cancelled');
        $this->assertDatabaseHas('aws_marketplace_subscriptions', ['agreement_id' => 'agmt-1', 'status' => 'unsubscribed']);
    }

    #[Test]
    public function an_end_superseded_by_a_renewal_marks_it_superseded_without_cancelling(): void
    {
        Event::fake([SubscriptionSuperseded::class, SubscriptionCancelled::class]);

        $this->landed('agmt-1', 'arn-1');

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Ended - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-superseded',
                'agreement' => ['id' => 'agmt-1', 'status' => 'RENEWED'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionSuperseded::class, fn ($e) => $e->subscription->id === 'agmt-1' && $e->event->agreementStatus === 'RENEWED');
        Event::assertNotDispatched(SubscriptionCancelled::class);
        $this->assertDatabaseHas('aws_marketplace_subscriptions', ['agreement_id' => 'agmt-1', 'status' => 'superseded']);
    }

    #[Test]
    public function a_replayed_event_is_deduplicated_by_its_request_id(): void
    {
        Event::fake([SubscriptionActivated::class, AwsMarketplaceEventReceived::class]);

        $payload = [
            'detail-type' => 'Purchase Agreement Created - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-dup',
                'agreement' => ['id' => 'agmt-1', 'intent' => 'NEW'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        Event::assertDispatchedTimes(SubscriptionActivated::class, 1);
        Event::assertDispatchedTimes(AwsMarketplaceEventReceived::class, 1);
        $this->assertDatabaseCount('aws_marketplace_processed_events', 1);
        $this->assertDatabaseHas('aws_marketplace_processed_events', ['event_key' => 'req-dup']);
    }

    #[Test]
    public function it_rejects_a_webhook_without_the_shared_secret(): void
    {
        $this->postWebhook([
            'detail-type' => 'License Updated - Manufacturer',
            'source' => 'aws.agreement-marketplace',
            'detail' => ['agreement' => ['id' => 'agmt-1'], 'license' => ['arn' => 'arn-1']],
        ], withSecret: false)->assertUnauthorized();
    }

    #[Test]
    public function a_failing_listener_returns_500_and_leaves_the_event_unmarked(): void
    {
        Event::listen(AwsMarketplaceEventReceived::class, function (): void {
            throw new RuntimeException('listener boom');
        });

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Created - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-boom',
                'agreement' => ['id' => 'agmt-1', 'intent' => 'NEW'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertStatus(500);

        $this->assertDatabaseCount('aws_marketplace_processed_events', 0);
    }

    #[Test]
    public function a_cancellation_for_an_unknown_subscription_is_dispatched_but_not_persisted(): void
    {
        Event::fake([SubscriptionCancelled::class, AwsMarketplaceEventReceived::class]);

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Ended - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-ghost',
                'agreement' => ['id' => 'ghost-1', 'status' => 'CANCELLED'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionCancelled::class, fn ($e) => $e->subscription->id === 'ghost-1');
        Event::assertDispatched(AwsMarketplaceEventReceived::class, fn ($e) => $e->subscription === null);
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 0);
    }

    #[Test]
    public function a_supersession_for_an_unknown_subscription_is_dispatched_but_not_persisted(): void
    {
        Event::fake([SubscriptionSuperseded::class]);

        $this->postWebhook([
            'detail-type' => 'Purchase Agreement Ended - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-superseded-ghost',
                'agreement' => ['id' => 'ghost-2', 'status' => 'REPLACED'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ])->assertOk();

        Event::assertDispatched(SubscriptionSuperseded::class, fn ($e) => $e->subscription->id === 'ghost-2' && $e->event->agreementStatus === 'REPLACED');
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 0);
    }

    #[Test]
    public function an_unrecognized_event_is_surfaced_only_through_the_generic_event(): void
    {
        Event::fake([AwsMarketplaceEventReceived::class]);

        $this->postWebhook([
            'detail-type' => 'Something Else',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-unknown',
                'agreement' => ['id' => 'agmt-x'],
            ],
        ])->assertOk();

        Event::assertDispatched(AwsMarketplaceEventReceived::class, fn ($e) => $e->subscription === null);
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 0);
    }
}
