<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use Illuminate\Support\Facades\Event;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use PHPUnit\Framework\Attributes\Test;

class PersistenceDisabledTest extends FeatureTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('marketplace-aws.persistence.enabled', false);
    }

    #[Test]
    public function the_stores_are_not_bound(): void
    {
        $this->assertFalse($this->app->bound(SubscriptionRepository::class));
        $this->assertFalse($this->app->bound(ProcessedEventStore::class));
    }

    #[Test]
    public function the_webhook_still_dispatches_without_persisting_or_deduplicating(): void
    {
        Event::fake([SubscriptionActivated::class]);

        $payload = [
            'detail-type' => 'Purchase Agreement Created - Proposer',
            'source' => 'aws.agreement-marketplace',
            'detail' => [
                'requestId' => 'req-1',
                'agreement' => ['id' => 'agmt-1', 'intent' => 'NEW'],
                'acceptor' => ['accountId' => 'acct-1'],
            ],
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        Event::assertDispatchedTimes(SubscriptionActivated::class, 2);
        $this->assertDatabaseCount('aws_marketplace_subscriptions', 0);
    }
}
