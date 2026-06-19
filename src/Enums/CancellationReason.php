<?php

namespace LMendes\LaravelAwsMarketplace\Enums;

/**
 * Why an AWS Marketplace subscription ended, normalized from the agreement's ended status. Unknown
 * covers a deprovisioned license (which carries no status) and any unrecognized status.
 */
enum CancellationReason: string
{
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Terminated = 'terminated';
    case Unknown = 'unknown';
}
