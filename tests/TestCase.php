<?php

namespace LMendes\LaravelAwsMarketplace\Tests;

use Illuminate\Foundation\Application;
use LMendes\LaravelAwsMarketplace\LaravelAwsMarketplaceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAwsMarketplaceServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
