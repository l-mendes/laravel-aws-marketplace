<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature\Console;

use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InstallCommandTest extends TestCase
{
    #[Test]
    public function it_publishes_and_reports_setup_without_migrating(): void
    {
        $this->artisan('aws-marketplace:install', ['--no-migrate' => true])
            ->assertSuccessful();
    }
}
