<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use LMendes\LaravelAwsMarketplace\Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    protected const SECRET = 'eventbridge-secret';

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('marketplace-aws.eventbridge.webhook_secret', self::SECRET);

        // Drop the route group middleware so coverage does not depend on the host app's 'api' group.
        $app['config']->set('marketplace-aws.routes.middleware', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(array $payload, bool $withSecret = true): \Illuminate\Testing\TestResponse
    {
        $headers = $withSecret ? ['X-Marketplace-Webhook-Secret' => self::SECRET] : [];

        return $this->postJson('/marketplace/aws/webhook', $payload, $headers);
    }
}
