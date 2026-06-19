<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Services;

use Aws\MarketplaceEntitlementService\MarketplaceEntitlementServiceClient;
use LMendes\LaravelAwsMarketplace\Services\AwsEntitlementService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class AwsEntitlementServiceTest extends MockeryTestCase
{
    #[Test]
    public function it_fetches_entitlements_for_a_license_following_pagination(): void
    {
        $client = Mockery::mock(MarketplaceEntitlementServiceClient::class);
        $client->shouldReceive('getEntitlements')
            ->once()
            ->with([
                'ProductCode' => 'prod-x',
                'Filter' => ['LICENSE_ARN' => ['arn-1']],
            ])
            ->andReturn(['Entitlements' => [['Dimension' => 'seats', 'Value' => ['IntegerValue' => 10]]], 'NextToken' => 'next']);

        $client->shouldReceive('getEntitlements')
            ->once()
            ->with([
                'ProductCode' => 'prod-x',
                'Filter' => ['LICENSE_ARN' => ['arn-1']],
                'NextToken' => 'next',
            ])
            ->andReturn(['Entitlements' => [['Dimension' => 'api', 'Value' => ['IntegerValue' => 0]]]]);

        $entitlements = (new AwsEntitlementService($client))->fetch('prod-x', 'arn-1');

        $this->assertCount(2, $entitlements);
        $this->assertSame('seats', $entitlements[0]->dimension);
        $this->assertSame(10, $entitlements[0]->units);
        $this->assertSame('api', $entitlements[1]->dimension);
        $this->assertNull($entitlements[1]->expiresAt);
    }
}
