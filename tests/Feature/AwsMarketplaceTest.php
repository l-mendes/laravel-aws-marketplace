<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use LMendes\LaravelAwsMarketplace\AwsMarketplace as AwsMarketplaceManager;
use LMendes\LaravelAwsMarketplace\DTO\MeterResult;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;
use LMendes\LaravelAwsMarketplace\Services\AwsEntitlementService;
use LMendes\LaravelAwsMarketplace\Services\AwsMeteringService;
use LMendes\LaravelAwsMarketplace\Services\AwsResolveCustomerService;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

class AwsMarketplaceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function the_facade_delegates_resolve_entitlements_and_meter_to_the_services(): void
    {
        $resolver = Mockery::mock(AwsResolveCustomerService::class);
        $resolver->shouldReceive('resolve')->once()->with('tok')->andReturn(new ResolvedCustomer(licenseArn: 'arn-1'));

        $entitlements = Mockery::mock(AwsEntitlementService::class);
        $entitlements->shouldReceive('fetch')->once()->with('prod-x', 'arn-1')->andReturn([]);

        $metering = Mockery::mock(AwsMeteringService::class);
        $metering->shouldReceive('meter')
            ->once()
            ->withArgs(fn (string $arn, string $account, UsageRecord ...$records): bool => $arn === 'arn-1' && $account === 'acct-1' && count($records) === 1)
            ->andReturn(new MeterResult(accepted: [['ok' => true]]));

        $this->app->instance(AwsResolveCustomerService::class, $resolver);
        $this->app->instance(AwsEntitlementService::class, $entitlements);
        $this->app->instance(AwsMeteringService::class, $metering);
        $this->app->forgetInstance(AwsMarketplaceManager::class);

        $this->assertSame('arn-1', AwsMarketplace::resolve('tok')->licenseArn);
        $this->assertSame([], AwsMarketplace::entitlements('prod-x', 'arn-1'));
        $this->assertSame([['ok' => true]], AwsMarketplace::meter('arn-1', 'acct-1', new UsageRecord(dimension: 'api', quantity: 3))->accepted);
    }
}
