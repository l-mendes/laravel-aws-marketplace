<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use Aws\MarketplaceEntitlementService\MarketplaceEntitlementServiceClient;
use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use LMendes\LaravelAwsMarketplace\AwsMarketplace;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends FeatureTestCase
{
    #[Test]
    public function it_binds_the_persistence_and_idempotency_stores_when_enabled(): void
    {
        $this->assertTrue($this->app->bound(SubscriptionRepository::class));
        $this->assertTrue($this->app->bound(ProcessedEventStore::class));
    }

    #[Test]
    public function it_registers_the_landing_and_webhook_routes(): void
    {
        $router = $this->app['router'];

        $this->assertTrue($router->has('marketplace.aws.landing'));
        $this->assertTrue($router->has('marketplace.aws.webhook'));
    }

    #[Test]
    public function it_resolves_the_aws_marketplace_entry_point(): void
    {
        $this->assertInstanceOf(AwsMarketplace::class, $this->app->make(AwsMarketplace::class));
    }

    #[Test]
    public function it_builds_the_marketplace_clients(): void
    {
        $this->assertInstanceOf(MarketplaceMeteringClient::class, $this->app->make(MarketplaceMeteringClient::class));
        $this->assertInstanceOf(MarketplaceEntitlementServiceClient::class, $this->app->make(MarketplaceEntitlementServiceClient::class));
    }

    #[Test]
    public function it_assumes_the_seller_role_when_a_role_arn_is_configured(): void
    {
        config()->set('marketplace-aws.role.arn', 'arn:aws:iam::111122223333:role/seller');
        config()->set('marketplace-aws.role.external_id', 'ext-1');
        $this->app->forgetInstance(MarketplaceMeteringClient::class);

        $this->assertInstanceOf(MarketplaceMeteringClient::class, $this->app->make(MarketplaceMeteringClient::class));
    }
}
