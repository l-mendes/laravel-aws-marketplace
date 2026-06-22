<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Console;

use Illuminate\Foundation\Application;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PruneProcessedEventsDisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('marketplace-aws.persistence.idempotency.enabled', false);
    }

    #[Test]
    public function it_warns_and_succeeds_when_idempotency_is_disabled(): void
    {
        $this->artisan('aws-marketplace:prune-events')->assertSuccessful();
    }
}
