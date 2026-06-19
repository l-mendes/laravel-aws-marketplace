<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use LMendes\LaravelAwsMarketplace\Services\AwsResolveCustomerService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class AwsResolveCustomerServiceTest extends MockeryTestCase
{
    #[Test]
    public function it_resolves_a_token_into_a_resolved_customer(): void
    {
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

        $customer = (new AwsResolveCustomerService($client))->resolve('tok');

        $this->assertSame('arn-1', $customer->licenseArn);
        $this->assertSame('acct-1', $customer->customerAccountId);
        $this->assertSame('cust-1', $customer->customerIdentifier);
        $this->assertSame('prod-x', $customer->productCode);
        $this->assertSame('arn-1', $customer->raw['LicenseArn']);
    }
}
