# Integrating laravel-aws-marketplace

A complete walkthrough for wiring an AWS Marketplace SaaS product into your Laravel app: the onboarding
handshake, the lifecycle webhook, entitlements, and metering. It targets the EventBridge + Concurrent
Agreements model (the default for new SaaS listings since June 1, 2026), not the legacy SNS topics.

## Contents

1. [The model in one minute](#1-the-model-in-one-minute)
2. [Install and configure](#2-install-and-configure)
3. [AWS-side setup](#3-aws-side-setup)
4. [Onboarding handshake (landing)](#4-onboarding-handshake-landing)
5. [Reacting to lifecycle events](#5-reacting-to-lifecycle-events)
6. [Renewals and replacements](#6-renewals-and-replacements)
7. [Entitlements](#7-entitlements)
8. [Metering](#8-metering)
9. [What to wire per product type](#9-what-to-wire-per-product-type)
10. [Persistence](#10-persistence)
11. [Local testing](#11-local-testing)
12. [Event reference](#12-event-reference)

## 1. The model in one minute

- Each AWS agreement is one subscription, keyed by its **agreement id** (`Subscription->id`). That id
  is present on every lifecycle event, so it is what you key on.
- The **LicenseArn** (`Subscription->licenseArn`) is the operational handle for GetEntitlements and
  BatchMeterUsage. It is not on the agreement events; it is filled in from the License Updated event, so
  it can be null until then.
- A **renewal or replacement is a new agreement**, with a new LicenseArn and therefore a new
  subscription. AWS gives no pointer back to the prior agreement, so the library never guesses a link.
  You bind a subscription to your tenant at the landing step and reconcile renewals by the buyer account
  id (see section 6).
- The buyer account id (`customerAccountId`) is the buyer's AWS account. It is stable across renewals
  and is your anchor for reconciliation, but it is not unique to a subscription (one account can hold
  several).

## 2. Install and configure

```bash
composer require l-mendes/laravel-aws-marketplace
php artisan aws-marketplace:install
```

`aws-marketplace:install` publishes the config and migrations and runs them. Then set:

```dotenv
AWS_MARKETPLACE_REGION=us-east-1

AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET=a-long-random-string
AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_HEADER=X-Marketplace-Webhook-Secret

# Optional: assume your seller-account role for the Marketplace APIs.
AWS_MARKETPLACE_ROLE_ARN=
AWS_MARKETPLACE_ROLE_EXTERNAL_ID=
AWS_MARKETPLACE_ROLE_SESSION_NAME=laravel-aws-marketplace
```

The Marketplace APIs (ResolveCustomer, GetEntitlements, BatchMeterUsage) must run under the AWS account
that published the listing. If your app runs in a different account, set `AWS_MARKETPLACE_ROLE_ARN` to a
role in the seller account that the app can assume; otherwise the default AWS credential chain is used.

The IAM principal needs: `aws-marketplace:ResolveCustomer`, `aws-marketplace:GetEntitlements`,
`aws-marketplace:BatchMeterUsage` (and `sts:AssumeRole` if a role ARN is set).

## 3. AWS-side setup

The package registers two routes:

- `POST|GET /marketplace/aws/landing`
- `POST /marketplace/aws/webhook`

Wire them on the AWS side:

- **Listing fulfillment URL**: set it to `https://app.example.com/marketplace/aws/landing`. AWS redirects
  the buyer here (with `x-amzn-marketplace-token`) right after they subscribe.
- **EventBridge**: create a rule that matches the SaaS events and sends them to your webhook through an
  API Destination whose connection adds the shared-secret header.

  ```json
  {
    "source": ["aws.agreement-marketplace"],
    "detail-type": [
      "Purchase Agreement Created - Proposer",
      "Purchase Agreement Amended - Proposer",
      "Purchase Agreement Ended - Proposer",
      "License Updated - Manufacturer",
      "License Deprovisioned - Manufacturer"
    ]
  }
  ```

  The API Destination connection must add a header named `X-Marketplace-Webhook-Secret` (or whatever you
  set `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_HEADER` to) with the value of
  `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET`. Requests without a matching secret are rejected with 401.

If you are both the manufacturer and the proposer (a direct public or private offer), you receive the
`- Proposer` variant of the agreement events; the License events are always `- Manufacturer`. The
package strips the role suffix when classifying, so either variant works.

## 4. Onboarding handshake (landing)

Implement `FulfillmentHandler` and bind it in the container. It receives a `ResolvedCustomer` (the result
of ResolveCustomer) and owns onboarding and the redirect. This is where you create or link your tenant
to the buyer and persist the identifiers you will need later.

```php
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response;

class MarketplaceFulfillment implements FulfillmentHandler
{
    public function fulfilled(ResolvedCustomer $customer): Response
    {
        // customer->licenseArn, customer->customerAccountId, customer->customerIdentifier, customer->productCode
        $tenant = Tenant::create([
            'aws_customer_account_id' => $customer->customerAccountId,
            'aws_license_arn' => $customer->licenseArn,
            'aws_product_code' => $customer->productCode,
        ]);

        auth()->login($tenant->owner);

        return redirect()->route('onboarding', $tenant);
    }

    public function failed(\Throwable $exception): Response
    {
        report($exception);

        return redirect()->route('subscribe.help');
    }
}
```

Bind it (for example in a service provider):

```php
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;

$this->app->bind(FulfillmentHandler::class, MarketplaceFulfillment::class);
```

Store the `licenseArn` and `customerAccountId` on the tenant: the lifecycle events let you find the
canonical subscription (keyed by agreement id) and join it back to this tenant by the LicenseArn.

## 5. Reacting to lifecycle events

The webhook verifies, parses, deduplicates, persists the subscription (keyed by agreement id), and
dispatches a typed event plus the catch-all `AwsMarketplaceEventReceived`. Key your listeners on
`$event->subscription->id`. Every event also carries `$event->event` (an `AwsMarketplaceEvent`) with the
native AWS fields and the raw payload.

```php
use Illuminate\Support\Facades\Event;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;

Event::listen(SubscriptionActivated::class, function (SubscriptionActivated $e) {
    // A new agreement. Usually redundant with the landing (you already onboarded there);
    // use it to confirm activation or to provision if you onboard purely from events.
});

Event::listen(SubscriptionUpdated::class, function (SubscriptionUpdated $e) {
    // Entitlements changed (an amendment or a license update). Re-sync:
    if ($e->changes->has('entitlements')) {
        $sub = $e->subscription;
        $entitlements = \LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace::entitlements(
            $sub->productCode,
            $sub->licenseArn,
        );
        // ... apply $entitlements to the tenant.
    }
});

Event::listen(SubscriptionCancelled::class, function (SubscriptionCancelled $e) {
    // $e->reason is Cancelled, Expired, Terminated, or Unknown.
    // For usage products, report final usage before $e->event->finalMeteringDeadline (see section 8).
    // Then revoke access.
});
```

`AwsMarketplaceEventReceived` fires for every delivery, including ones with no actionable transition
(its `subscription` is null when there was nothing to persist). Use it as a catch-all or for auditing.

## 6. Renewals and replacements

When an agreement renews or is replaced, AWS creates a new agreement with a new LicenseArn and ends the
old one (with status RENEWED or REPLACED, which this library treats as "not a cancellation" so access is
not revoked). You receive:

- `SubscriptionRenewed` or `SubscriptionReplaced` for the new agreement (intent RENEW or REPLACE on the
  Purchase Agreement Created event). `$e->subscription->id` is the new agreement id.
- then a `SubscriptionUpdated` (License Updated) carrying the new LicenseArn.

Because AWS exposes no link from the new agreement to the old one, you reconcile it to the existing
customer using the buyer account id:

```php
Event::listen(SubscriptionRenewed::class, function (SubscriptionRenewed $e) {
    $account = $e->event->customerAccountId;
    $tenant = Tenant::where('aws_customer_account_id', $account)->first();

    if ($tenant !== null) {
        $tenant->update(['aws_agreement_id' => $e->subscription->id]);
    }
    // else: queue for reconciliation (see the caveat below).
});
```

Caveat: a single AWS account can hold more than one agreement for the same product (this is what
Concurrent Agreements enables), which in your app can mean more than one tenant per account. In that
case the buyer account id alone does not identify which tenant the renewal continues, and neither the
library nor any API can resolve it (AWS emits no link). Handle that case as a business decision (for
example, ask an admin from that account to confirm) rather than guessing.

## 7. Entitlements

For contract products, fetch the buyer's entitlements after the landing and whenever a
`SubscriptionUpdated` arrives:

```php
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;

$entitlements = AwsMarketplace::entitlements($productCode, $licenseArn);

foreach ($entitlements as $entitlement) {
    // $entitlement->dimension, $entitlement->units, $entitlement->expiresAt, $entitlement->raw
}
```

## 8. Metering

For usage-based products, report metered usage hourly with the LicenseArn and buyer account id:

```php
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;

$result = AwsMarketplace::meter($licenseArn, $customerAccountId,
    new UsageRecord(dimension: 'requests', quantity: 1200),
    new UsageRecord(dimension: 'gb_processed', quantity: 34),
);

// $result->accepted, $result->rejected, $result->raw
```

Best practices: send records every hour (cumulative; AWS de-duplicates on the hour), and send a record
with quantity 0 even when there was no usage. When a subscription ends, the `License Deprovisioned`
event (a `SubscriptionCancelled` with the LicenseArn) opens a one-hour final reporting window, surfaced
as `$e->event->finalMeteringDeadline`. Submit any unreported usage before that deadline; afterward AWS
rejects it.

## 9. What to wire per product type

| Product type | Landing | Entitlements | Metering | Key events |
| --- | --- | --- | --- | --- |
| Pay-As-You-Go (usage) | yes | no | yes (hourly) | Activated, Cancelled (final-meter window) |
| Contract | yes | yes (on Updated) | no | Activated, Updated, Cancelled |
| Contract with consumption | yes | yes (on Updated) | yes (overage) | Activated, Updated, Cancelled |

Renewals and replacements apply to all three.

## 10. Persistence

Subscriptions are persisted in `aws_marketplace_subscriptions`, keyed by `agreement_id`, with
`license_arn`, `product_code`, `customer_account_id`, `customer_identifier`, `status`,
`current_period_end`, a polymorphic `owner`, and the raw payload. Idempotency receipts live in
`aws_marketplace_processed_events`.

- **Attach your tenant** through the `owner` morph (`AwsSubscription::owner()`), or keep your own foreign
  key on the tenant; both work.
- **Find a subscription by LicenseArn** to join a landing binding to the canonical row:
  `app(SubscriptionRepository::class)->findByLicenseArn($licenseArn)`.
- **Swap the models** via `marketplace-aws.persistence.model` and
  `marketplace-aws.persistence.idempotency.model`.
- **Disable persistence** (manage state yourself) with `marketplace-aws.persistence.enabled = false`;
  the webhook still dispatches events (at-least-once, no dedup).
- **Prune idempotency receipts**: the dedup table grows one row per event. It is never pruned
  automatically. Set a retention window in days with `AWS_MARKETPLACE_IDEMPOTENCY_TTL_DAYS` (config
  `marketplace-aws.persistence.idempotency.ttl`, null by default = keep forever) and run
  `php artisan aws-marketplace:prune-events`, or pass `--days=` to override. Schedule it once a TTL is
  set:

  ```php
  // routes/console.php (or your scheduler)
  Schedule::command('aws-marketplace:prune-events')->daily();
  ```

  Receipts only need to outlive the EventBridge retry window, so a generous TTL (for example 30 days) is
  safe; pruning an old receipt only re-enables dedup for an event that is effectively never resent.

## 11. Local testing

You can drive the package without a separate app using Orchestra Testbench, or simulate a webhook
against your running app. A webhook delivery is a plain JSON POST with the secret header:

```bash
curl -X POST https://app.example.com/marketplace/aws/webhook \
  -H 'Content-Type: application/json' \
  -H 'X-Marketplace-Webhook-Secret: a-long-random-string' \
  -d '{
        "detail-type": "License Updated - Manufacturer",
        "source": "aws.agreement-marketplace",
        "detail": {
          "requestId": "test-1",
          "agreement": { "id": "agmt-123" },
          "license": { "arn": "arn:aws:license-manager::111122223333:license/l-abc" },
          "product": { "code": "your-product-code" },
          "acceptor": { "accountId": "111122223333" }
        }
      }'
```

## 12. Event reference

All events use source `aws.agreement-marketplace`. The detail-type ends with ` - Proposer` or
` - Manufacturer`.

| detail-type | detail.agreement.intent / status | Dispatched event | Carries LicenseArn |
| --- | --- | --- | --- |
| Purchase Agreement Created | intent NEW | `SubscriptionActivated` | no |
| Purchase Agreement Created | intent RENEW | `SubscriptionRenewed` | no |
| Purchase Agreement Created | intent REPLACE | `SubscriptionReplaced` | no |
| Purchase Agreement Amended | intent AMEND | `SubscriptionUpdated` | no |
| License Updated | - | `SubscriptionUpdated` | yes |
| Purchase Agreement Ended | status CANCELLED / EXPIRED / TERMINATED | `SubscriptionCancelled` | no |
| Purchase Agreement Ended | status RENEWED / REPLACED | none (catch-all only) | no |
| License Deprovisioned | - | `SubscriptionCancelled` | yes |

`AwsMarketplaceEvent` (on every dispatched event as `$event->event`) carries: `type`, `detailType`,
`licenseArn`, `agreementId`, `customerAccountId`, `productCode`, `intent`, `agreementStatus`,
`idempotencyKey`, `currentPeriodEnd`, `finalMeteringDeadline`, `cancellationReason`, `changes`, `raw`.
