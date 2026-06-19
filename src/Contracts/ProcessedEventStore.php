<?php

namespace LMendes\LaravelAwsMarketplace\Contracts;

use DateTimeInterface;

/**
 * Records which events have already been processed, so retried EventBridge deliveries are deduplicated.
 * Keyed by the event's idempotency key (detail.requestId, falling back to the envelope id). The package
 * ships an Eloquent-backed implementation, bound only when persistence and idempotency are enabled; when
 * unbound the webhook pipeline processes every delivery (at-least-once).
 */
interface ProcessedEventStore
{
    public function isProcessed(string $key): bool;

    /**
     * Record the key as processed. Must be idempotent and race-safe (an insert-or-ignore against the
     * unique key index), so calling it more than once or concurrently is harmless.
     */
    public function markProcessed(string $key): void;

    /**
     * Delete receipts created strictly before the cutoff and return how many were removed, bounding the
     * table's growth. Drives the aws-marketplace:prune-events command; removing an old receipt only
     * re-enables dedup for an equally old, effectively never-resent event.
     */
    public function prune(DateTimeInterface $before): int;
}
