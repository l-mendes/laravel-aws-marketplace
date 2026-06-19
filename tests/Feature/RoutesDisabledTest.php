<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

class RoutesDisabledTest extends FeatureTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('marketplace-aws.routes.enabled', false);
    }

    #[Test]
    public function it_registers_no_routes_when_routing_is_disabled(): void
    {
        $router = $this->app['router'];

        $this->assertFalse($router->has('marketplace.aws.landing'));
        $this->assertFalse($router->has('marketplace.aws.webhook'));
    }
}
