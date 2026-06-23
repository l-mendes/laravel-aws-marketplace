<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\Services;

use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Aws\Result;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use LMendes\LaravelAwsMarketplace\DTO\MeteredRecord;
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

        $this->assertCount(1, $result->accepted);
        $this->assertInstanceOf(MeteredRecord::class, $result->accepted[0]);
        $this->assertSame([], $result->rejected);
        $this->assertArrayHasKey('Results', $result->raw);
    }

    #[Test]
    public function it_partitions_results_by_status_and_maps_each_record_into_a_dto(): void
    {
        $client = Mockery::mock(MarketplaceMeteringClient::class);
        $client->shouldReceive('batchMeterUsage')
            ->once()
            ->andReturn(new Result([
                'Results' => [
                    [
                        'UsageRecord' => [
                            'Timestamp' => new DateTimeImmutable('2026-06-23T10:00:00+00:00'),
                            'CustomerAWSAccountId' => 'acct-1',
                            'Dimension' => 'api',
                            'Quantity' => 5,
                        ],
                        'MeteringRecordId' => 'rec-ok',
                        'Status' => 'Success',
                    ],
                    [
                        'UsageRecord' => ['CustomerAWSAccountId' => 'acct-1', 'Dimension' => 'api', 'Quantity' => 1],
                        'MeteringRecordId' => 'rec-nosub',
                        'Status' => 'CustomerNotSubscribed',
                    ],
                    [
                        'UsageRecord' => ['Dimension' => 'api', 'Quantity' => 2],
                        'MeteringRecordId' => 'rec-dup',
                        'Status' => 'DuplicateRecord',
                    ],
                    [
                        'UsageRecord' => ['Dimension' => 'api', 'Quantity' => 3],
                        'Status' => 'SomethingNewFromAws',
                    ],
                ],
                'UnprocessedRecords' => [
                    ['CustomerAWSAccountId' => 'acct-1', 'Dimension' => 'storage', 'Quantity' => 9],
                ],
            ]));

        $result = (new AwsMeteringService($client))->meter(
            'arn-1',
            'acct-1',
            new UsageRecord(dimension: 'api', quantity: 5),
        );

        $this->assertCount(1, $result->accepted);
        $accepted = $result->accepted[0];
        $this->assertInstanceOf(MeteredRecord::class, $accepted);
        $this->assertSame('api', $accepted->dimension);
        $this->assertSame(5, $accepted->quantity);
        $this->assertSame('acct-1', $accepted->customerAccountId);
        $this->assertSame('rec-ok', $accepted->meteringRecordId);
        $this->assertInstanceOf(CarbonInterface::class, $accepted->timestamp);
        $this->assertSame('2026-06-23T10:00:00+00:00', $accepted->timestamp->toIso8601String());
        $this->assertSame('Success', $accepted->raw['Status']);

        $this->assertCount(1, $result->duplicates);
        $this->assertSame('rec-dup', $result->duplicates[0]->meteringRecordId);
        $this->assertSame(2, $result->duplicates[0]->quantity);

        $this->assertCount(2, $result->rejected);
        $this->assertSame('rec-nosub', $result->rejected[0]->meteringRecordId);
        $this->assertSame(1, $result->rejected[0]->quantity);
        $this->assertNull($result->rejected[1]->meteringRecordId);
        $this->assertSame(3, $result->rejected[1]->quantity);
        $this->assertSame('SomethingNewFromAws', $result->rejected[1]->raw['Status']);

        $this->assertCount(1, $result->unprocessed);
        $unprocessed = $result->unprocessed[0];
        $this->assertInstanceOf(MeteredRecord::class, $unprocessed);
        $this->assertSame('storage', $unprocessed->dimension);
        $this->assertSame(9, $unprocessed->quantity);
        $this->assertSame('acct-1', $unprocessed->customerAccountId);
        $this->assertNull($unprocessed->meteringRecordId);
    }

    #[Test]
    public function it_returns_empty_buckets_when_aws_omits_the_result_lists(): void
    {
        $client = Mockery::mock(MarketplaceMeteringClient::class);
        $client->shouldReceive('batchMeterUsage')
            ->once()
            ->andReturn(new Result([]));

        $result = (new AwsMeteringService($client))->meter(
            'arn-1',
            'acct-1',
            new UsageRecord(dimension: 'api', quantity: 5),
        );

        $this->assertSame([], $result->accepted);
        $this->assertSame([], $result->rejected);
        $this->assertSame([], $result->duplicates);
        $this->assertSame([], $result->unprocessed);
    }
}
