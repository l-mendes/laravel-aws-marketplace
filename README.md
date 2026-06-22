# laravel-aws-marketplace

[![CI](https://github.com/l-mendes/laravel-aws-marketplace/actions/workflows/ci.yml/badge.svg)](https://github.com/l-mendes/laravel-aws-marketplace/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/l-mendes/laravel-aws-marketplace.svg)](https://packagist.org/packages/l-mendes/laravel-aws-marketplace)
[![Total Downloads](https://img.shields.io/packagist/dt/l-mendes/laravel-aws-marketplace.svg)](https://packagist.org/packages/l-mendes/laravel-aws-marketplace)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A standalone AWS Marketplace integration for Laravel SaaS products. It handles the post-subscribe
registration handshake, contract entitlements, metered usage, and the EventBridge lifecycle webhook, and
gives you typed domain events to react to: normalized, but never hiding the native AWS fields.

It targets the current AWS Marketplace model: EventBridge agreement and license events (source
`aws.agreement-marketplace`) under Concurrent Agreements, the default for new SaaS listings since June 1,
2026. It does not use the legacy SNS subscription/entitlement topics, which cannot distinguish concurrent
agreements because they do not carry the LicenseArn.

This README is the overview and quick start. For the complete, step-by-step integration guide (AWS-side
setup, a worked example, renewals, metering windows, persistence, testing, and production), see
[INTEGRATION.md](INTEGRATION.md).

## What it does

- Resolve the post-subscribe registration token (`x-amzn-marketplace-token`) into a `ResolvedCustomer`
  (LicenseArn, customer account id, customer identifier, product code).
- Entitlements: fetch a buyer's contract entitlements for a license (GetEntitlements, paginated).
- Metering: report metered usage (BatchMeterUsage), keyed by LicenseArn and customer account id.
- Lifecycle webhook: verify EventBridge deliveries with a shared-secret header, parse them, deduplicate
  retries, persist the subscription, and dispatch typed domain events.
- Client wiring: builds the Marketplace clients, optionally assuming a seller-account role via STS.

Use the parts your pricing model needs:

| Product type | What you use |
| --- | --- |
| Pay-As-You-Go (usage) | resolve + meter + lifecycle events |
| Contract | resolve + entitlements + lifecycle events |
| Contract with consumption | resolve + entitlements + meter + lifecycle events |

## Identity model (important)

Each AWS agreement is one subscription, keyed by its agreement id (`Subscription->id`), the identifier
present on every lifecycle event. The LicenseArn (`Subscription->licenseArn`) is the operational handle
for GetEntitlements and BatchMeterUsage; it is filled in from the License Updated event, so it may be null
until then. The agreement id and the LicenseArn first appear together on that License Updated event, which
is where you finalize the link between the tenant you bound at the landing and the canonical subscription.

Renewal and replacement mint a new agreement, with a new LicenseArn and therefore a new subscription. AWS
exposes no pointer from it back to the prior agreement, so this library never guesses a link across
agreements. You bind a subscription to your tenant at the landing step, and on a renewal you reconcile the
new subscription to the existing customer using the buyer account id (which AWS does provide) against your
own mapping.

## Requirements

- PHP `^8.3`
- Laravel `^12`

## Installation

```bash
composer require l-mendes/laravel-aws-marketplace
php artisan aws-marketplace:install
```

The service provider is auto-discovered. `aws-marketplace:install` publishes the config and migrations
and runs them. To do it by hand:

```bash
php artisan vendor:publish --tag=aws-marketplace-config
php artisan vendor:publish --tag=aws-marketplace-migrations
php artisan migrate
```

## Configuration

```dotenv
# The Marketplace APIs are served from us-east-1; keep this as us-east-1 even if your app runs elsewhere.
AWS_MARKETPLACE_REGION=us-east-1

# Shared secret used to authenticate EventBridge webhook deliveries.
AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET=change-me
AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_HEADER=X-Marketplace-Webhook-Secret

# Optional: assume a seller-account role for the Marketplace APIs.
# Leave empty to use the default AWS credential chain (env, shared config, instance/task role).
AWS_MARKETPLACE_ROLE_ARN=
AWS_MARKETPLACE_ROLE_EXTERNAL_ID=
AWS_MARKETPLACE_ROLE_SESSION_NAME=laravel-aws-marketplace
```

The package registers two routes; point your AWS listing and EventBridge rule at them:

- Fulfillment URL (listing) -> `https://app.example.com/marketplace/aws/landing`
- EventBridge API Destination -> `https://app.example.com/marketplace/aws/webhook`, with a connection
  that adds the secret header matching `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET`.

## Quick start

### 1. Fulfillment (the landing handshake)

Implement `FulfillmentHandler` and bind it. It receives the resolved buyer after they subscribe and owns
onboarding plus the redirect. Bind your tenant to the LicenseArn here.

```php
use App\Models\Tenant;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response;

class MarketplaceFulfillment implements FulfillmentHandler
{
    public function fulfilled(ResolvedCustomer $customer): Response
    {
        // Key on the LicenseArn (unique to this agreement) so a replayed landing reuses the same tenant.
        $tenant = Tenant::firstOrCreate(
            ['aws_license_arn' => $customer->licenseArn],
            [
                'aws_customer_account_id' => $customer->customerAccountId,
                'aws_product_code' => $customer->productCode,
            ],
        );

        return redirect()->route('onboarding', $tenant);
    }

    public function failed(\Throwable $exception): Response
    {
        report($exception);

        return redirect()->route('subscribe.help');
    }
}
```

```php
$this->app->bind(FulfillmentHandler::class, MarketplaceFulfillment::class);
```

The marketplace routes are stateless (the `api` middleware group), so start the buyer's session on a
`web` route rather than in the handler. INTEGRATION.md shows the signed-redirect pattern that handles this
and CSRF.

### 2. Lifecycle events

Listen for the typed events. Key on `$event->subscription->id` (the agreement id); every event also
carries the normalized `$event->event` with the native AWS fields (`licenseArn`, `agreementId`, `intent`,
`agreementStatus`, `customerAccountId`, `productCode`) and the raw payload.

```php
use Illuminate\Support\Facades\Event;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionRenewed;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;

Event::listen(SubscriptionUpdated::class, function (SubscriptionUpdated $e) {
    // Entitlements changed; re-fetch and re-sync.
});

Event::listen(SubscriptionRenewed::class, function (SubscriptionRenewed $e) {
    // New agreement (new id). Reconcile to your customer by the buyer account:
    $account = $e->event->customerAccountId;
    // ... attach $e->subscription->id to that customer's tenant.
});

Event::listen(SubscriptionCancelled::class, function (SubscriptionCancelled $e) {
    // $e->reason is Cancelled, Expired, Terminated, or Unknown.
});
```

The full set: `SubscriptionActivated`, `SubscriptionRenewed`, `SubscriptionReplaced`,
`SubscriptionUpdated` (with a `changes` hint), `SubscriptionSuperseded` (the old agreement after a renewal
or replacement, not a cancellation), `SubscriptionCancelled` (with a `reason`), plus the catch-all
`AwsMarketplaceEventReceived` dispatched for every delivery.

### 3. Entitlements and metering

```php
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;

$entitlements = AwsMarketplace::entitlements($productCode, $licenseArn);

AwsMarketplace::meter($licenseArn, $customerAccountId, new UsageRecord(
    dimension: 'your_usage_dimension',
    quantity: 7,
));
```

## Documentation

[INTEGRATION.md](INTEGRATION.md) is the full, end-to-end walkthrough:

- Install and configure, IAM, and the seller-account role
- The landing handshake, with sessions and CSRF handled
- Connecting AWS (EventBridge rule, API Destination, the shared secret, dead-letter queue)
- Reacting to lifecycle events with a worked provisioner and the tenant-to-agreement link
- Entitlements and metering (a scheduled reporter and the final-metering window)
- Renewals and replacements, persistence, local testing, a production checklist, and troubleshooting

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
