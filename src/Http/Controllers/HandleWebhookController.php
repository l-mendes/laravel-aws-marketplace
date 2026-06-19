<?php

namespace LMendes\LaravelAwsMarketplace\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Enums\EventType;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;
use LMendes\LaravelAwsMarketplace\Events\AwsMarketplaceEventReceived;
use LMendes\LaravelAwsMarketplace\Events\LifecycleEventFactory;
use LMendes\LaravelAwsMarketplace\Services\EventParser;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Handles an inbound AWS Marketplace EventBridge webhook (already authenticated by the verification
 * middleware). The pipeline parses the delivery, deduplicates retries by the event's idempotency key,
 * maintains the subscription keyed by its agreement id (filling in the LicenseArn from license events),
 * dispatches the specific lifecycle event plus the generic AwsMarketplaceEventReceived (both carrying
 * the subscription), and marks the event processed on success.
 *
 * EventBridge needs no acknowledgement, so a handled delivery returns 200 and a dispatch failure returns
 * 500 to have EventBridge retry. Idempotency is check-before-dispatch, mark-after-success: a duplicate
 * re-sends 200 without reprocessing, and a failed delivery is left unmarked so the retry reprocesses it.
 * When persistence is disabled the canonical and dedup steps are skipped and the pipeline degrades to
 * the per-event, at-least-once behavior.
 */
class HandleWebhookController
{
    public function __construct(
        private readonly EventParser $parser,
        private readonly Dispatcher $events,
        private readonly Container $container,
        private readonly LifecycleEventFactory $lifecycleEvents,
    ) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $event = $this->parser->parse($request->all());

        if ($this->alreadyProcessed($event)) {
            return new Response('', Response::HTTP_OK);
        }

        $subscription = $this->persist($event);

        $success = true;

        try {
            $lifecycleEvent = $this->lifecycleEvents->make($event, $subscription ?? $this->transient($event));

            if ($lifecycleEvent !== null) {
                $this->events->dispatch($lifecycleEvent);
            }

            $this->events->dispatch(new AwsMarketplaceEventReceived($event, $subscription));
        } catch (Throwable $exception) {
            $success = false;
        }

        if ($success) {
            $this->markProcessed($event);
        }

        return new Response('', $success ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function alreadyProcessed(AwsMarketplaceEvent $event): bool
    {
        if ($event->idempotencyKey === null || ! $this->container->bound(ProcessedEventStore::class)) {
            return false;
        }

        return $this->container->make(ProcessedEventStore::class)->isProcessed($event->idempotencyKey);
    }

    private function markProcessed(AwsMarketplaceEvent $event): void
    {
        if ($event->idempotencyKey === null || ! $this->container->bound(ProcessedEventStore::class)) {
            return;
        }

        $this->container->make(ProcessedEventStore::class)->markProcessed($event->idempotencyKey);
    }

    /**
     * Maintain the subscription for the event and return it, or null when there is nothing to persist
     * (no agreement id, an Unknown event, persistence disabled, or a terminal event for a subscription
     * never seen). Keyed by the agreement id; the LicenseArn and other columns are overlaid from the
     * event without clobbering what the event does not carry.
     */
    private function persist(AwsMarketplaceEvent $event): ?Subscription
    {
        if ($event->agreementId === null || $event->type === EventType::Unknown) {
            return null;
        }

        if (! $this->container->bound(SubscriptionRepository::class)) {
            return null;
        }

        $repository = $this->container->make(SubscriptionRepository::class);

        $existing = $repository->find($event->agreementId);
        $status = $event->type->toSubscriptionStatus();

        if ($existing === null && ($status === SubscriptionStatus::Unsubscribed || $status === SubscriptionStatus::Superseded)) {
            return null;
        }

        return $repository->save($this->overlay($event, $existing));
    }

    /**
     * Build the subscription to persist by overlaying the event onto the existing row: take each event
     * value when present, otherwise keep what is stored, so a partial event never nulls data.
     */
    private function overlay(AwsMarketplaceEvent $event, ?Subscription $existing): Subscription
    {
        $keepExistingRaw = $existing !== null && $existing->raw !== [];

        return new Subscription(
            id: $event->agreementId,
            licenseArn: $event->licenseArn ?? $existing?->licenseArn,
            productCode: $event->productCode ?? $existing?->productCode,
            customerAccountId: $event->customerAccountId ?? $existing?->customerAccountId,
            customerIdentifier: $existing?->customerIdentifier,
            status: $event->type->toSubscriptionStatus() ?? $existing?->status,
            currentPeriodEnd: $event->currentPeriodEnd ?? $existing?->currentPeriodEnd,
            entitlements: $existing?->entitlements ?? [],
            raw: $keepExistingRaw ? $existing->raw : $event->raw,
        );
    }

    /**
     * A non-persisted subscription built straight from the event, carried by the lifecycle event when
     * there is no stored row (persistence disabled, or an agreement seen only through this event).
     */
    private function transient(AwsMarketplaceEvent $event): Subscription
    {
        return new Subscription(
            id: $event->agreementId ?? '',
            licenseArn: $event->licenseArn,
            productCode: $event->productCode,
            customerAccountId: $event->customerAccountId,
            status: $event->type->toSubscriptionStatus(),
            currentPeriodEnd: $event->currentPeriodEnd,
            raw: $event->raw,
        );
    }
}
