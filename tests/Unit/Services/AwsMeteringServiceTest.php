<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Aws\Result;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;
use LMendes\LaravelAwsMarketplace\Services\AwsMeteringService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PHPUnit\Framework\Attributes\Test;

class AwsMeteringServiceTest extends MockeryTestCase
{
    #[Test]
    public function it_reports_usage_keyed_by_the_license_arn_and_account(): void
    {
        $client = Mockery::mock(MarketplaceMeteringClient::class);
        $client->shouldReceive('batchMeterUsage')
            ->once()
            ->with(Mockery::on(function (array $arg): bool {
                $record = $arg['UsageRecords'][0];

                return $record['LicenseArn'] === 'arn-1'
                    && $record['CustomerAWSAccountId'] === 'acct-1'
                    && $record['Dimension'] === 'api'
                    && $record['Quantity'] === 5
                    && is_int($record['Timestamp']);
            }))
            ->andReturn(new Result(['Results' => [['Status' => 'Success']], 'UnprocessedRecords' => []]));

        $result = (new AwsMeteringService($client))->meter(
            'arn-1',
            'acct-1',
            new UsageRecord(dimension: 'api', quantity: 5),
        );

        $this->assertSame([['Status' => 'Success']], $result->accepted);
        $this->assertSame([], $result->rejected);
        $this->assertArrayHasKey('Results', $result->raw);
    }
}
