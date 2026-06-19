<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\Tests\Fixtures\RecordingFulfillmentHandler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

class LandingTest extends FeatureTestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_resolves_the_token_and_hands_the_customer_to_the_fulfillment_handler(): void
    {
        $handler = new RecordingFulfillmentHandler;
        $this->app->instance(FulfillmentHandler::class, $handler);

        $client = Mockery::mock(MarketplaceMeteringClient::class);
        $client->shouldReceive('resolveCustomer')
            ->once()
            ->with(['RegistrationToken' => 'tok'])
            ->andReturn([
                'LicenseArn' => 'arn-1',
                'CustomerAWSAccountId' => 'acct-1',
                'CustomerIdentifier' => 'cust-1',
                'ProductCode' => 'prod-x',
            ]);
        $this->app->instance(MarketplaceMeteringClient::class, $client);

        $this->post('/marketplace/aws/landing', ['x-amzn-marketplace-token' => 'tok'])->assertOk();

        $this->assertSame('arn-1', $handler->fulfilledWith?->licenseArn);
        $this->assertSame('acct-1', $handler->fulfilledWith?->customerAccountId);
        $this->assertSame('prod-x', $handler->fulfilledWith?->productCode);
    }

    #[Test]
    public function it_calls_failed_when_the_registration_token_is_missing(): void
    {
        $handler = new RecordingFulfillmentHandler;
        $this->app->instance(FulfillmentHandler::class, $handler);

        $this->post('/marketplace/aws/landing', [])->assertStatus(422);

        $this->assertNotNull($handler->failedWith);
    }
}
