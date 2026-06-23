<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Feature;

use Aws\AwsClientInterface;
use Aws\Credentials\CredentialsInterface;
use Aws\MarketplaceEntitlementService\MarketplaceEntitlementServiceClient;
use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Aws\Sts\StsClient;
use LMendes\LaravelAwsMarketplace\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CredentialsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_PROFILE'] as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }

        parent::tearDown();
    }

    #[Test]
    public function it_uses_the_default_credential_chain_when_nothing_is_configured(): void
    {
        $this->hostCredentials('AKIA-HOST', 'host-secret');

        $credentials = $this->credentialsOf($this->metering());

        $this->assertSame('AKIA-HOST', $credentials->getAccessKeyId());
        $this->assertSame('host-secret', $credentials->getSecretKey());
    }

    #[Test]
    public function it_uses_dedicated_static_credentials_for_the_marketplace_clients(): void
    {
        $this->hostCredentials('AKIA-HOST', 'host-secret');
        config()->set('marketplace-aws.credentials.key', 'AKIA-MARKETPLACE');
        config()->set('marketplace-aws.credentials.secret', 'marketplace-secret');

        foreach ([$this->metering(), $this->entitlement()] as $client) {
            $credentials = $this->credentialsOf($client);

            $this->assertSame('AKIA-MARKETPLACE', $credentials->getAccessKeyId());
            $this->assertSame('marketplace-secret', $credentials->getSecretKey());
        }
    }

    #[Test]
    public function it_passes_the_session_token_when_configured(): void
    {
        config()->set('marketplace-aws.credentials.key', 'AKIA-MARKETPLACE');
        config()->set('marketplace-aws.credentials.secret', 'marketplace-secret');
        config()->set('marketplace-aws.credentials.token', 'marketplace-token');

        $credentials = $this->credentialsOf($this->metering());

        $this->assertSame('marketplace-token', $credentials->getSecurityToken());
    }

    #[Test]
    public function it_ignores_dedicated_credentials_when_the_secret_is_missing(): void
    {
        $this->hostCredentials('AKIA-HOST', 'host-secret');
        config()->set('marketplace-aws.credentials.key', 'AKIA-MARKETPLACE');

        $credentials = $this->credentialsOf($this->metering());

        $this->assertSame('AKIA-HOST', $credentials->getAccessKeyId());
    }

    #[Test]
    public function it_sources_the_assumed_role_from_the_default_chain_when_no_dedicated_keys_are_set(): void
    {
        $this->hostCredentials('AKIA-HOST', 'host-secret');
        config()->set('marketplace-aws.role.arn', 'arn:aws:iam::111122223333:role/seller');

        $source = $this->credentialsOf($this->sts());

        $this->assertSame('AKIA-HOST', $source->getAccessKeyId());
    }

    #[Test]
    public function it_sources_the_assumed_role_from_the_dedicated_credentials_when_set(): void
    {
        $this->hostCredentials('AKIA-HOST', 'host-secret');
        config()->set('marketplace-aws.role.arn', 'arn:aws:iam::111122223333:role/seller');
        config()->set('marketplace-aws.credentials.key', 'AKIA-MARKETPLACE');
        config()->set('marketplace-aws.credentials.secret', 'marketplace-secret');

        $source = $this->credentialsOf($this->sts());

        $this->assertSame('AKIA-MARKETPLACE', $source->getAccessKeyId());
        $this->assertSame('marketplace-secret', $source->getSecretKey());
    }

    private function hostCredentials(string $key, string $secret): void
    {
        putenv("AWS_ACCESS_KEY_ID={$key}");
        putenv("AWS_SECRET_ACCESS_KEY={$secret}");
    }

    private function metering(): MarketplaceMeteringClient
    {
        $this->app->forgetInstance(MarketplaceMeteringClient::class);

        return $this->app->make(MarketplaceMeteringClient::class);
    }

    private function entitlement(): MarketplaceEntitlementServiceClient
    {
        $this->app->forgetInstance(MarketplaceEntitlementServiceClient::class);

        return $this->app->make(MarketplaceEntitlementServiceClient::class);
    }

    private function sts(): StsClient
    {
        $this->app->forgetInstance(StsClient::class);

        return $this->app->make(StsClient::class);
    }

    private function credentialsOf(AwsClientInterface $client): CredentialsInterface
    {
        return $client->getCredentials()->wait();
    }
}
