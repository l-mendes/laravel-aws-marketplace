<?php

namespace LMendes\LaravelAwsMarketplace\DTO;

/**
 * A hint of what an Updated event touched. AWS does not enumerate exactly what changed, so this is
 * coarse (an amendment or license update is reported as an entitlements change) and may be empty; the
 * safe handler re-fetches entitlements unconditionally.
 */
final readonly class SubscriptionChanges
{
    public const ENTITLEMENTS = 'entitlements';

    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public array $changed = [],
    ) {}

    public function has(string $aspect): bool
    {
        return in_array($aspect, $this->changed, true);
    }

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }
}
