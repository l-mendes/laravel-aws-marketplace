<?php

namespace LMendes\LaravelAwsMarketplace;

use Aws\Credentials\CredentialProvider;
use Aws\MarketplaceEntitlementService\MarketplaceEntitlementServiceClient;
use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Aws\Sdk;
use Aws\Sts\StsClient;
use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Support\ServiceProvider;
use LMendes\LaravelAwsMarketplace\Console\InstallCommand;
use LMendes\LaravelAwsMarketplace\Console\PruneProcessedEventsCommand;
use LMendes\LaravelAwsMarketplace\Contracts\ProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Contracts\SubscriptionRepository;
use LMendes\LaravelAwsMarketplace\Http\Controllers\HandleLandingController;
use LMendes\LaravelAwsMarketplace\Http\Controllers\HandleWebhookController;
use LMendes\LaravelAwsMarketplace\Http\Middleware\VerifyEventBridgeWebhook;
use LMendes\LaravelAwsMarketplace\Repositories\EloquentProcessedEventStore;
use LMendes\LaravelAwsMarketplace\Repositories\EloquentSubscriptionRepository;
use LMendes\LaravelAwsMarketplace\Services\AwsEntitlementService;
use LMendes\LaravelAwsMarketplace\Services\AwsMeteringService;
use LMendes\LaravelAwsMarketplace\Services\AwsResolveCustomerService;

class LaravelAwsMarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marketplace-aws.php', 'marketplace-aws');

        $this->app->singleton(StsClient::class, function () {
            return $this->sdk()->createSts($this->stsConfig());
        });

        $this->app->singleton(MarketplaceMeteringClient::class, function () {
            return $this->sdk()->createMarketplaceMetering($this->clientConfig());
        });

        $this->app->singleton(MarketplaceEntitlementServiceClient::class, function () {
            return $this->sdk()->createMarketplaceEntitlementService($this->clientConfig());
        });

        $this->app->singleton(AwsResolveCustomerService::class, function ($app) {
            return new AwsResolveCustomerService($app->make(MarketplaceMeteringClient::class));
        });

        $this->app->singleton(AwsEntitlementService::class, function ($app) {
            return new AwsEntitlementService($app->make(MarketplaceEntitlementServiceClient::class));
        });

        $this->app->singleton(AwsMeteringService::class, function ($app) {
            return new AwsMeteringService($app->make(MarketplaceMeteringClient::class));
        });

        $this->app->singleton(AwsMarketplace::class, function ($app) {
            return new AwsMarketplace(
                $app->make(AwsResolveCustomerService::class),
                $app->make(AwsEntitlementService::class),
                $app->make(AwsMeteringService::class),
            );
        });
    }

    /**
     * Persistence binding runs here rather than in register() because it reads the final config
     * (testbench applies environment config after providers register).
     */
    public function boot(): void
    {
        if ($this->app->make('config')->get('marketplace-aws.persistence.enabled', true)) {
            $this->app->singleton(SubscriptionRepository::class, function ($app) {
                return new EloquentSubscriptionRepository($app['config']->get('marketplace-aws.persistence.model'));
            });

            if ($this->app->make('config')->get('marketplace-aws.persistence.idempotency.enabled', true)) {
                $this->app->singleton(ProcessedEventStore::class, function ($app) {
                    return new EloquentProcessedEventStore($app['config']->get('marketplace-aws.persistence.idempotency.model'));
                });
            }
        }

        $this->publishes([
            __DIR__.'/../config/marketplace-aws.php' => $this->app->configPath('marketplace-aws.php'),
        ], 'aws-marketplace-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'aws-marketplace-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PruneProcessedEventsCommand::class]);
        }

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        $config = $this->app->make('config');

        if (! $config->get('marketplace-aws.routes.enabled', true)) {
            return;
        }

        $routes = $config->get('marketplace-aws.routes', []);
        $namePrefix = $routes['name_prefix'] ?? 'marketplace.aws.';
        $landing = $routes['landing'] ?? [];
        $webhook = $routes['webhook'] ?? [];

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->group([
            'prefix' => $routes['prefix'] ?? 'marketplace/aws',
            'middleware' => $routes['middleware'] ?? [],
        ], function (Router $router) use ($landing, $webhook, $namePrefix) {
            $router->match(
                $landing['methods'] ?? ['GET', 'POST'],
                $landing['uri'] ?? 'landing',
                HandleLandingController::class,
            )->middleware($landing['middleware'] ?? [])
                ->name($namePrefix.'landing');

            $router->post(
                $webhook['uri'] ?? 'webhook',
                HandleWebhookController::class,
            )->middleware(array_merge([VerifyEventBridgeWebhook::class], $webhook['middleware'] ?? []))
                ->name($namePrefix.'webhook');
        });
    }

    /**
     * Resolve the credentials for the Marketplace API clients. A seller-role ARN takes precedence and is
     * assumed via STS; otherwise the dedicated seller-account credentials are used directly, and when
     * neither is configured the default AWS provider chain applies.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(): array
    {
        $config = ['region' => config('marketplace-aws.region')];

        $roleArn = config('marketplace-aws.role.arn');
        $credentials = $this->dedicatedCredentials();

        if ($roleArn) {
            $config['credentials'] = $this->sellerRoleCredentials($roleArn);
        } elseif ($credentials !== null) {
            $config['credentials'] = $credentials;
        }

        return $config;
    }

    /**
     * Assume the seller-account role so the Marketplace APIs run under the account that owns the
     * listing, even when the application is hosted in a different AWS account. The STS client that
     * performs the assumption is itself sourced from the dedicated credentials (or the default chain),
     * so the assumption can originate off-AWS without borrowing the application's global credentials.
     */
    private function sellerRoleCredentials(string $roleArn): callable
    {
        $params = [
            'RoleArn' => $roleArn,
            'RoleSessionName' => config('marketplace-aws.role.session_name'),
        ];

        if ($externalId = config('marketplace-aws.role.external_id')) {
            $params['ExternalId'] = $externalId;
        }

        return CredentialProvider::memoize(
            CredentialProvider::assumeRole([
                'client' => $this->app->make(StsClient::class),
                'assume_role_params' => $params,
            ])
        );
    }

    /**
     * Dedicated static credentials for the seller account, kept separate from the application's global
     * AWS credentials so they never collide with the host app's S3 / SES / SQS configuration. Returns
     * null when not fully configured, leaving the default AWS provider chain in place.
     *
     * @return array{key: string, secret: string, token?: string}|null
     */
    private function dedicatedCredentials(): ?array
    {
        $key = config('marketplace-aws.credentials.key');
        $secret = config('marketplace-aws.credentials.secret');

        if (empty($key) || empty($secret)) {
            return null;
        }

        $credentials = ['key' => (string) $key, 'secret' => (string) $secret];

        if ($token = config('marketplace-aws.credentials.token')) {
            $credentials['token'] = (string) $token;
        }

        return $credentials;
    }

    /**
     * @return array<string, mixed>
     */
    private function stsConfig(): array
    {
        $config = ['region' => config('marketplace-aws.region')];

        if (($credentials = $this->dedicatedCredentials()) !== null) {
            $config['credentials'] = $credentials;
        }

        return $config;
    }

    /**
     * Build the AWS SDK from the package config. Credentials are left to the default AWS provider chain
     * (environment, shared config, instance/task role) unless a seller role is assumed.
     */
    private function sdk(): Sdk
    {
        return new Sdk([
            'region' => config('marketplace-aws.region'),
            'version' => 'latest',
        ]);
    }
}
