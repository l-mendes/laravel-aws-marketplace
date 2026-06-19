# laravel-aws-marketplace

A standalone AWS Marketplace integration for Laravel SaaS products. It handles the registration
handshake, contract entitlements, metered usage, and the EventBridge lifecycle webhook, and gives you
clean domain events to react to, normalized but never hiding the native AWS fields.

It targets the current AWS Marketplace model: EventBridge agreement and license events (source
`aws.agreement-marketplace`) under Concurrent Agreements, which became the default for new SaaS
listings on June 1, 2026. It does not use the legacy SNS subscription/entitlement topics, which cannot
distinguish concurrent agreements because they do not carry the LicenseArn.

## What it does

- **Resolve** the post-subscribe registration token (`x-amzn-marketplace-token`) into a
  `ResolvedCustomer` (LicenseArn, customer account id, customer identifier, product code).
- **Entitlements**: fetch a buyer's contract entitlements for a license (GetEntitlements, paginated).
- **Metering**: report metered usage (BatchMeterUsage), keyed by LicenseArn and customer account id.
- **Lifecycle webhook**: verify EventBridge deliveries with a shared-secret header, parse them, persist
  the subscription, and dispatch typed domain events.
- **Client wiring**: builds the Marketplace clients, optionally assuming a seller-account role via STS.

It works for all three SaaS pricing models; use the parts you need:

| Product type | What you use |
| --- | --- |
| Pay-As-You-Go (usage) | resolve + meter + lifecycle events |
| Contract | resolve + entitlements + lifecycle events |
| Contract with consumption | resolve + entitlements + meter + lifecycle events |

## Identity model (important)

Each AWS agreement is one subscription, keyed by its **agreement id** (`Subscription->id`), the
identifier present on every lifecycle event. The **LicenseArn** (`Subscription->licenseArn`) is the
operational handle for GetEntitlements and BatchMeterUsage; it is filled in from the License Updated
event, so it may be null until then.

Renewal and replacement mint a **new** agreement, with a new LicenseArn and therefore a new
subscription. AWS exposes no pointer from it back to the prior agreement, so this library never guesses
a link across agreements. You bind a subscription to your tenant at the landing step, and on a renewal
you reconcile the new subscription to the existing customer using the buyer account id (which AWS does
provide) against your own mapping.

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

Point your AWS listing and EventBridge rule at the registered routes:

- **Fulfillment URL** (listing) to `https://app.example.com/marketplace/aws/landing`
- **EventBridge API Destination** to `https://app.example.com/marketplace/aws/webhook`, with a
  connection that adds the secret header matching `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET`.

## Usage

For the complete walkthrough (AWS-side EventBridge setup, per-event listeners, renewal reconciliation,
metering windows, persistence, and local testing) see [INTEGRATION.md](INTEGRATION.md).

### 1. Fulfillment (the landing handshake)

Implement `FulfillmentHandler` and bind it. It receives the resolved buyer when they land after
subscribing, and owns onboarding plus the redirect. Persist the LicenseArn and customer account id on
your tenant here.

```php
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response;

class MarketplaceFulfillment implements FulfillmentHandler
{
    public function fulfilled(ResolvedCustomer $customer): Response
    {
        $tenant = Tenant::firstOrCreate(
            ['aws_customer_account_id' => $customer->customerAccountId],
            ['aws_license_arn' => $customer->licenseArn, 'aws_product_code' => $customer->productCode],
        );

        return redirect()->route('onboarding', $tenant);
    }

    public function failed(\Throwable $exception): Response
    {
        return redirect()->route('subscribe.help');
    }
}
```

```php
$this->app->bind(FulfillmentHandler::class, MarketplaceFulfillment::class);
```

### 2. Lifecycle events

Listen for the typed events. Key on `$event->subscription->id` (the agreement id); every event also
carries the normalized `$event->event` with the native AWS fields (`licenseArn`, `agreementId`,
`intent`, `agreementStatus`, `customerAccountId`, `productCode`) and the raw payload.

```php
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
`SubscriptionUpdated` (with a `changes` hint), `SubscriptionSuperseded` (the old agreement after a
renewal or replacement, not a cancellation), `SubscriptionCancelled` (with a `reason`), plus the
catch-all `AwsMarketplaceEventReceived` dispatched for every delivery.

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

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
