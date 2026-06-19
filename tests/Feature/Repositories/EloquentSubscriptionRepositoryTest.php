<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Repositories;

use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;
use LMendes\LaravelAwsMarketplace\Models\AwsSubscription;
use LMendes\LaravelAwsMarketplace\Repositories\EloquentSubscriptionRepository;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EloquentSubscriptionRepositoryTest extends TestCase
{
    private function repository(): EloquentSubscriptionRepository
    {
        return new EloquentSubscriptionRepository(AwsSubscription::class);
    }

    #[Test]
    public function it_saves_and_finds_a_subscription_by_its_agreement_id(): void
    {
        $repository = $this->repository();

        $repository->save(new Subscription(
            id: 'agmt-1',
            licenseArn: 'arn-1',
            productCode: 'prod-x',
            customerAccountId: 'acct-1',
            status: SubscriptionStatus::Active,
        ));

        $found = $repository->find('agmt-1');

        $this->assertNotNull($found);
        $this->assertSame('agmt-1', $found->id);
        $this->assertSame('arn-1', $found->licenseArn);
        $this->assertSame(SubscriptionStatus::Active, $found->status);
        $this->assertNull($repository->find('missing'));
    }

    #[Test]
    public function it_finds_a_subscription_by_its_license_arn(): void
    {
        $repository = $this->repository();

        $repository->save(new Subscription(id: 'agmt-1', licenseArn: 'arn-1'));

        $this->assertSame('agmt-1', $repository->findByLicenseArn('arn-1')?->id);
        $this->assertNull($repository->findByLicenseArn('arn-unknown'));
    }

    #[Test]
    public function it_upserts_keyed_by_the_agreement_id(): void
    {
        $repository = $this->repository();

        $repository->save(new Subscription(id: 'agmt-1', status: SubscriptionStatus::Active));
        $repository->save(new Subscription(id: 'agmt-1', licenseArn: 'arn-1', status: SubscriptionStatus::Unsubscribed));

        $this->assertDatabaseCount('aws_marketplace_subscriptions', 1);
        $found = $repository->find('agmt-1');
        $this->assertSame('arn-1', $found->licenseArn);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $found->status);
    }
}
