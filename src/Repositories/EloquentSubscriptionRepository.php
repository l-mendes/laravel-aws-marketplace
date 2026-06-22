<?php

namespace LMendes\LaravelAwsMarketplace\Repositories;

use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Models\AwsSubscription;

class EloquentSubscriptionRepository implements SubscriptionRepository
{
    /**
     * @param  class-string<AwsSubscription>  $model
     */
    public function __construct(
        private readonly string $model,
    ) {}

    public function find(string $agreementId): ?Subscription
    {
        $model = $this->model::query()->where('agreement_id', $agreementId)->first();

        return $model !== null ? $this->toDataObject($model) : null;
    }

    public function findByLicenseArn(string $licenseArn): ?Subscription
    {
        $model = $this->model::query()->where('license_arn', $licenseArn)->first();

        return $model !== null ? $this->toDataObject($model) : null;
    }

    public function save(Subscription $subscription): Subscription
    {
        $model = $this->model::query()->updateOrCreate(
            ['agreement_id' => $subscription->id],
            [
                'license_arn' => $subscription->licenseArn,
                'product_code' => $subscription->productCode,
                'customer_account_id' => $subscription->customerAccountId,
                'customer_identifier' => $subscription->customerIdentifier,
                'status' => $subscription->status,
                'current_period_end' => $subscription->currentPeriodEnd,
                'raw' => $subscription->raw,
            ],
        );

        return $this->toDataObject($model);
    }

    private function toDataObject(AwsSubscription $model): Subscription
    {
        return new Subscription(
            id: $model->agreement_id,
            licenseArn: $model->license_arn,
            productCode: $model->product_code,
            customerAccountId: $model->customer_account_id,
            customerIdentifier: $model->customer_identifier,
            status: $model->status,
            currentPeriodEnd: $model->current_period_end,
            raw: $model->raw ?? [],
        );
    }
}
