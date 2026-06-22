<?php

namespace LMendes\LaravelAwsMarketplace\Facades;

use Illuminate\Support\Facades\Facade;
use LMendes\LaravelAwsMarketplace\AwsMarketplace as AwsMarketplaceManager;

/**
 * @method static \LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer resolve(string $registrationToken)
 * @method static list<\LMendes\LaravelAwsMarketplace\DTO\Entitlement> entitlements(string $productCode, string $licenseArn)
 * @method static \LMendes\LaravelAwsMarketplace\DTO\MeterResult meter(string $licenseArn, string $customerAccountId, \LMendes\LaravelAwsMarketplace\DTO\UsageRecord ...$records)
 *
 * @see AwsMarketplaceManager
 */
class AwsMarketplace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AwsMarketplaceManager::class;
    }
}
