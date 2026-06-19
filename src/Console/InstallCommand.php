<?php

namespace LMendes\LaravelAwsMarketplace\Console;

use Illuminate\Console\Command;

/**
 * Publishes the AWS Marketplace config and migrations, runs the migrations, and prints the remaining
 * manual setup (environment variables and the listing/EventBridge URLs).
 */
class InstallCommand extends Command
{
    protected $signature = 'aws-marketplace:install
        {--no-migrate : Skip running migrations}
        {--force : Overwrite published files}';

    protected $description = 'Publish the AWS Marketplace config and migrations and run them';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', ['--tag' => 'aws-marketplace-config', '--force' => $force]);
        $this->call('vendor:publish', ['--tag' => 'aws-marketplace-migrations', '--force' => $force]);

        if (! $this->option('no-migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('AWS Marketplace installed.');
        $this->components->bulletList([
            'Set AWS_MARKETPLACE_REGION (defaults to us-east-1).',
            'Set AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET to the secret your EventBridge API Destination signs requests with.',
            'Optionally set AWS_MARKETPLACE_ROLE_ARN to assume your seller-account role for the Marketplace APIs.',
            'Point your SaaS listing fulfillment URL at the marketplace/aws/landing route.',
            'Point your EventBridge rule (source aws.agreement-marketplace) at the marketplace/aws/webhook route.',
        ]);

        return self::SUCCESS;
    }
}
