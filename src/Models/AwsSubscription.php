<?php

namespace LMendes\LaravelAwsMarketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;

/**
 * Default Eloquent model for a persisted AWS Marketplace subscription, keyed by `agreement_id`. Swap it
 * through `marketplace-aws.persistence.model` to integrate with your own schema. The polymorphic `owner`
 * links the subscription to your user/tenant; `license_arn` is the operational handle, filled in once a
 * License event links it to the agreement.
 *
 * @property string $agreement_id
 * @property ?string $license_arn
 * @property ?string $product_code
 * @property ?string $customer_account_id
 * @property ?string $customer_identifier
 * @property ?SubscriptionStatus $status
 * @property ?\Illuminate\Support\Carbon $current_period_end
 */
class AwsSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'current_period_end' => 'datetime',
        'raw' => 'array',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('marketplace-aws.persistence.table', 'aws_marketplace_subscriptions');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
