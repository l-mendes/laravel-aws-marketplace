# Integrating laravel-aws-marketplace

This is an end-to-end, step-by-step guide to wiring an AWS Marketplace SaaS product into a Laravel
application: installing the package, modelling your customer, the post-subscribe landing handshake, the
AWS-side EventBridge setup, reacting to the lifecycle webhook, contract entitlements, metered usage,
renewals, persistence, testing, and going to production. Follow it top to bottom and you will have a
working integration; the worked example uses a `Tenant` model, but the shape applies to whatever you
call your customer (account, workspace, organization).

It targets the current AWS Marketplace model: EventBridge agreement and license events (source
`aws.agreement-marketplace`) under Concurrent Agreements, the default for new SaaS listings since June 1,
2026. It does not use the legacy SNS subscription/entitlement topics, which cannot distinguish concurrent
agreements because they do not carry the LicenseArn.

## Contents

1. [How the integration fits together](#1-how-the-integration-fits-together)
2. [Prerequisites](#2-prerequisites)
3. [Step 1: Install the package](#3-step-1-install-the-package)
4. [Step 2: Configure the package](#4-step-2-configure-the-package)
5. [Step 3: Model your customer](#5-step-3-model-your-customer)
6. [Step 4: The landing handshake](#6-step-4-the-landing-handshake)
7. [Step 5: Connect AWS to your app](#7-step-5-connect-aws-to-your-app)
8. [Step 6: React to lifecycle events](#8-step-6-react-to-lifecycle-events)
9. [Step 7: Entitlements (contract products)](#9-step-7-entitlements-contract-products)
10. [Step 8: Metering (usage products)](#10-step-8-metering-usage-products)
11. [Renewals and replacements](#11-renewals-and-replacements)
12. [What to wire per product type](#12-what-to-wire-per-product-type)
13. [Persistence](#13-persistence)
14. [Local testing](#14-local-testing)
15. [Production checklist](#15-production-checklist)
16. [Troubleshooting](#16-troubleshooting)
17. [Reference](#17-reference)

## 1. How the integration fits together

An AWS Marketplace SaaS integration has two halves, and this package gives you both:

- A synchronous landing handshake. Right after a buyer subscribes, AWS redirects their browser (an HTTP
  POST) to your fulfillment URL with a one-time `x-amzn-marketplace-token`. You exchange that token for
  the buyer's identity (ResolveCustomer), onboard them, and redirect into your app.
- An asynchronous lifecycle webhook. From then on, AWS publishes agreement and license events to
  EventBridge, which forwards them to your webhook. You react to activation, changes, renewals, and
  cancellation.

The identity model in one minute. Read this before writing any code; it explains every later decision.

- Each AWS agreement is one subscription, keyed by its agreement id (`Subscription->id`). That id is on
  every lifecycle event, so it is the stable key you store and look up by.
- The LicenseArn (`Subscription->licenseArn`) is the operational handle for GetEntitlements and
  BatchMeterUsage. It is not on the agreement events; it arrives on the License Updated event, so it can
  be null until then.
- ResolveCustomer (the landing) gives you the LicenseArn and the buyer account id, but no agreement id.
  The agreement id and the LicenseArn first appear together on the License Updated event. That event is
  therefore the moment you finalize the link between the tenant you bound at the landing (which knows the
  LicenseArn) and the canonical subscription (keyed by the agreement id). This single fact drives the
  provisioning logic in Step 6.
- A renewal or replacement is a brand-new agreement, with a new LicenseArn and therefore a new
  subscription. AWS gives no pointer back to the prior agreement, so the package never guesses a link.
  You reconcile renewals to the existing customer by the buyer account id.
- The buyer account id (`customerAccountId`) is the buyer's AWS account. It is stable across renewals and
  is your reconciliation anchor, but it is not unique to a subscription (one account can hold several).

The happy path for a brand-new subscription, in order (the events can interleave; do not depend on the
exact ordering between the landing and the events, only on each step being idempotent):

```
Buyer subscribes on AWS
        |
        v
[Browser POST] -----> /marketplace/aws/landing      (ResolveCustomer -> LicenseArn, account id; you onboard + redirect)
        |
        |  (around the same time, AWS publishes to EventBridge)
        v
[EventBridge] ------> /marketplace/aws/webhook       Purchase Agreement Created (intent NEW) -> SubscriptionActivated
[EventBridge] ------> /marketplace/aws/webhook       License Updated (carries LicenseArn)    -> SubscriptionUpdated   <-- link finalized here
```

## 2. Prerequisites

- PHP `^8.3` and Laravel `^12`.
- A published AWS Marketplace SaaS listing (Pay-As-You-Go, Contract, or Contract with consumption), and
  access to the AWS account that owns it (the "seller account").
- The application reachable over HTTPS at a stable hostname for the landing and webhook URLs. For local
  development against real AWS you can use a tunnel such as ngrok (see Step 14).
- AWS credentials available to your app through the standard provider chain (environment variables,
  shared config, or an instance/task role). If the app runs in a different AWS account than the listing,
  either set dedicated seller-account credentials or a role in the seller account that the app can assume
  (see Step 2).

## 3. Step 1: Install the package

```bash
composer require l-mendes/laravel-aws-marketplace
php artisan aws-marketplace:install
```

The service provider is auto-discovered. `aws-marketplace:install` publishes the config and the two
migrations and runs them, then prints the remaining manual setup. It accepts `--no-migrate` (publish but
do not migrate) and `--force` (overwrite published files).

To do the same by hand:

```bash
php artisan vendor:publish --tag=aws-marketplace-config
php artisan vendor:publish --tag=aws-marketplace-migrations
php artisan migrate
```

This creates two tables: `aws_marketplace_subscriptions` (the canonical subscription per agreement) and
`aws_marketplace_processed_events` (webhook idempotency receipts). Both are covered in Step 13.

## 4. Step 2: Configure the package

Set these in `.env`:

```dotenv
# The AWS Marketplace metering and entitlement APIs are served from us-east-1. Keep this as us-east-1
# even if the rest of your app runs in another region.
AWS_MARKETPLACE_REGION=us-east-1

# Shared secret that authenticates inbound EventBridge webhook deliveries. Generate a long random value
# (for example: php -r "echo bin2hex(random_bytes(32));"). The same value is configured on the AWS side
# in Step 5.
AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET=replace-with-a-long-random-string
AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_HEADER=X-Marketplace-Webhook-Secret

# Optional: dedicated credentials for the seller account, used only by the Marketplace clients. Set these
# when the Marketplace account is separate from the one hosting the app and you want to authenticate with
# the seller account's own key/secret. They are kept apart from the global AWS_ACCESS_KEY_ID /
# AWS_SECRET_ACCESS_KEY the rest of the app uses, so the two never collide. Leave empty to use the default
# credential chain.
AWS_MARKETPLACE_ACCESS_KEY_ID=
AWS_MARKETPLACE_SECRET_ACCESS_KEY=
AWS_MARKETPLACE_SESSION_TOKEN=

# Optional: assume your seller-account role for the Marketplace APIs. Leave empty to use the default AWS
# credential chain (single-account or local development).
AWS_MARKETPLACE_ROLE_ARN=
AWS_MARKETPLACE_ROLE_EXTERNAL_ID=
AWS_MARKETPLACE_ROLE_SESSION_NAME=laravel-aws-marketplace
```

### Credentials and the seller account

The Marketplace APIs (ResolveCustomer, GetEntitlements, BatchMeterUsage) are authorized against the
account that published the listing (the seller account). There are three ways to supply credentials,
listed in the order the package prefers them:

- **Same account / default chain.** Your app runs in the seller account, or already has credentials for
  it on the default AWS chain. Leave everything below empty; the default chain (env, shared config,
  instance/task role) is used.
- **Dedicated seller-account credentials.** The seller account is separate from the account hosting the
  app and you have an IAM user in it. Set `AWS_MARKETPLACE_ACCESS_KEY_ID` and
  `AWS_MARKETPLACE_SECRET_ACCESS_KEY` (and `AWS_MARKETPLACE_SESSION_TOKEN` for temporary credentials).
  These are used only by the Marketplace clients and never touch the global
  `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` the rest of your app uses for S3, SES, SQS, and so on, so
  the two cannot collide. This is the simplest option for local development against the seller account.
- **Assumed seller-account role.** Create a role in the seller account that trusts your source
  principal, grant it the Marketplace permissions below, and set `AWS_MARKETPLACE_ROLE_ARN` to it (plus
  `AWS_MARKETPLACE_ROLE_EXTERNAL_ID` if the trust policy requires it). The package assumes that role via
  STS for every Marketplace call. The assumption is sourced from the dedicated credentials above when
  they are set, otherwise from the default chain; either way it is an ordinary STS call, so it works
  off-AWS (local development) as well as from an instance/task role in production.

The IAM principal that ends up making the calls needs:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "aws-marketplace:ResolveCustomer",
        "aws-marketplace:GetEntitlements",
        "aws-marketplace:BatchMeterUsage"
      ],
      "Resource": "*"
    }
  ]
}
```

If you assume a seller role, the source principal (the dedicated credentials when set, otherwise the
app's default identity) additionally needs `sts:AssumeRole` on that role's ARN, and the role's trust
policy must allow that principal.

### What the config file controls

The published `config/marketplace-aws.php` exposes everything you may want to change. The defaults are
production-sensible; the keys you are most likely to touch:

| Key | Default | Purpose |
| --- | --- | --- |
| `region` | `us-east-1` | Region for the Marketplace API clients. |
| `credentials.key` / `credentials.secret` / `credentials.token` | null / null / null | Dedicated seller-account credentials for the Marketplace clients, kept separate from the app's global AWS credentials. |
| `role.arn` / `role.external_id` / `role.session_name` | null / null / `laravel-aws-marketplace` | Seller-role assumption (sourced from `credentials.*` when set, else the default chain). |
| `eventbridge.webhook_secret` | null | Shared secret the webhook middleware checks. |
| `eventbridge.webhook_secret_header` | `X-Marketplace-Webhook-Secret` | Header the secret is read from. |
| `persistence.enabled` | `true` | Persist subscriptions and dedupe events. See Step 13. |
| `persistence.model` / `persistence.table` | `AwsSubscription` / `aws_marketplace_subscriptions` | Subscription storage. |
| `persistence.idempotency.*` | enabled, `AwsProcessedEvent`, null TTL | Webhook dedup receipts and pruning. |
| `routes.enabled` | `true` | Register the landing and webhook routes. |
| `routes.prefix` | `marketplace/aws` | URL prefix for both routes. |
| `routes.middleware` | `['api']` | Middleware group for both routes. See the CSRF and session note in Step 4. |
| `routes.name_prefix` | `marketplace.aws.` | Route name prefix (`marketplace.aws.landing`, `marketplace.aws.webhook`). |

### A note on route middleware, sessions, and CSRF

The package registers both routes under one middleware group, `['api']` by default:

- `GET|POST marketplace/aws/landing` (name `marketplace.aws.landing`)
- `POST marketplace/aws/webhook` (name `marketplace.aws.webhook`, with the secret-verification middleware
  in front)

`api` is stateless: no session, no cookies, no CSRF. That is exactly right for the webhook (a
server-to-server POST from EventBridge). It has a consequence for the landing, which is a real browser
navigation: you cannot start a logged-in session there with `Auth::login()`, because there is no session
cookie under `api`. And you must not move the landing under the full `web` group either, because `web`
enables CSRF and the landing is a cross-site POST from AWS with no CSRF token (it would 419).

The clean pattern, used in Step 4, is to keep the marketplace routes on `api` and have the landing
redirect the browser to a short-lived signed URL on a normal `web` route in your app, which is where you
establish the session. Keep this in mind now; the code is in Step 4.

## 5. Step 3: Model your customer

The package stores the canonical subscription for you (Step 13). What it cannot know is how a subscription
maps to your own customer record. Add the AWS identifiers to whatever model represents a paying customer.
In this guide that is `Tenant`.

```php
// database/migrations/xxxx_add_marketplace_columns_to_tenants_table.php
Schema::table('tenants', function (Blueprint $table) {
    $table->string('plan')->default('pending');
    $table->unsignedInteger('seats')->default(0);
    $table->string('status')->default('pending'); // pending, active, suspended, cancelled

    $table->string('aws_product_code')->nullable();
    $table->string('aws_customer_account_id')->nullable()->index();
    $table->string('aws_license_arn')->nullable()->index();   // bound at the landing
    $table->string('aws_agreement_id')->nullable()->unique(); // filled in from the License Updated event
    $table->timestamp('current_period_end')->nullable();
});
```

Why these four AWS columns:

- `aws_license_arn` is what you learn at the landing (ResolveCustomer). You bind the tenant to it there.
- `aws_agreement_id` is the canonical subscription key. You learn it from the events and store it the
  first time an event carries both the LicenseArn and the agreement id (the License Updated event).
- `aws_customer_account_id` is the renewal reconciliation anchor.
- `aws_product_code` is needed to call GetEntitlements.

This guide uses your own columns as the source of truth (the simplest approach). The package's
`AwsSubscription` row also has a polymorphic `owner`, so you can attach the tenant to it and get an
Eloquent relation instead; that optional approach is described in Step 13.

## 6. Step 4: The landing handshake

Implement `FulfillmentHandler`. The landing controller calls it after resolving the registration token,
and it owns onboarding plus the redirect response.

```php
// app/Marketplace/MarketplaceFulfillment.php
namespace App\Marketplace;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MarketplaceFulfillment implements FulfillmentHandler
{
    /**
     * Called when the registration token resolves. $customer carries licenseArn, customerAccountId,
     * customerIdentifier, and productCode (but no agreement id). Create or fetch the tenant, bind it to
     * the license, then hand the browser off to a signed web route that can start a session.
     */
    public function fulfilled(ResolvedCustomer $customer): Response
    {
        $tenant = DB::transaction(function () use ($customer) {
            // Key on the LicenseArn so that a refreshed or replayed landing reuses the same tenant
            // instead of creating a duplicate. The LicenseArn is unique to this agreement.
            $tenant = Tenant::firstOrNew(['aws_license_arn' => $customer->licenseArn]);

            $tenant->aws_customer_account_id = $customer->customerAccountId;
            $tenant->aws_product_code = $customer->productCode;

            if (! $tenant->exists) {
                $tenant->name = 'New subscription';
                $tenant->status = 'pending';
            }

            $tenant->save();

            return $tenant;
        });

        // The marketplace routes are stateless (api group), so we cannot log the buyer in here. Redirect
        // to a short-lived signed URL on a web route that can. See routes/web.php below.
        return redirect()->temporarySignedRoute(
            'marketplace.claim',
            now()->addMinutes(15),
            ['tenant' => $tenant->getKey()],
        );
    }

    /**
     * Called when the token is missing, invalid, expired, or ResolveCustomer fails.
     */
    public function failed(Throwable $exception): Response
    {
        report($exception);

        return redirect()->route('subscribe.help')
            ->with('error', 'We could not verify your AWS Marketplace subscription. Please try again.');
    }
}
```

Bind it in the container so the landing controller can resolve it:

```php
// app/Providers/AppServiceProvider.php
use App\Marketplace\MarketplaceFulfillment;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;

public function register(): void
{
    $this->app->bind(FulfillmentHandler::class, MarketplaceFulfillment::class);
}
```

Add the signed `web` route that starts the session. The `signed` middleware validates the signature; the
`web` group provides the session for `Auth::login()`.

```php
// routes/web.php
use App\Http\Controllers\Marketplace\ClaimController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'signed'])
    ->get('/marketplace/claim/{tenant}', ClaimController::class)
    ->name('marketplace.claim');
```

```php
// app/Http/Controllers/Marketplace/ClaimController.php
namespace App\Http\Controllers\Marketplace;

use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClaimController
{
    public function __invoke(Request $request, Tenant $tenant): RedirectResponse
    {
        // The buyer already has an account in your app: log them in and continue.
        if ($tenant->owner) {
            Auth::login($tenant->owner);

            return redirect()->route('onboarding', $tenant);
        }

        // First time from the Marketplace: send them through registration, remembering which tenant to
        // attach once they have created their user.
        $request->session()->put('claim_tenant_id', $tenant->getKey());

        return redirect()->route('register');
    }
}
```

How this resolves the session and CSRF problem from Step 2: the landing stays stateless and CSRF-free
(AWS can POST to it), and the signed URL is the trust handoff into your `web` routes, where sessions work
normally. For extra hardening, make the signed link single-use (for example by storing a nonce on the
tenant and clearing it in the controller).

The simpler alternative, if you prefer to log in directly on the landing: keep the landing on `api`, add
`'web'` to its per-route middleware in the config (`routes.landing.middleware`), and exclude
`marketplace/aws/landing` from CSRF in `bootstrap/app.php`
(`$middleware->validateCsrfTokens(except: ['marketplace/aws/landing'])`). The signed-redirect pattern
above avoids both edits and is recommended.

What you get from `ResolvedCustomer`:

| Property | Type | Notes |
| --- | --- | --- |
| `licenseArn` | `string` | Always present. The operational handle and your landing binding key. |
| `customerAccountId` | `?string` | The buyer's AWS account id (CustomerAWSAccountId). |
| `customerIdentifier` | `?string` | AWS CustomerIdentifier. |
| `productCode` | `?string` | Your product code. |
| `raw` | `array` | The untouched ResolveCustomer fields. |

## 7. Step 5: Connect AWS to your app

Two things to configure on the AWS side: the fulfillment URL (for the landing) and an EventBridge rule
(for the webhook).

### Fulfillment URL

In your SaaS listing configuration, set the fulfillment (SaaS) URL to:

```
https://app.example.com/marketplace/aws/landing
```

After a buyer subscribes, AWS POSTs them here with `x-amzn-marketplace-token` in the form body. The
package reads it, calls ResolveCustomer, and invokes your `FulfillmentHandler`.

### EventBridge rule and webhook

New SaaS agreements publish to the default event bus in your seller account, source
`aws.agreement-marketplace`. Forward the relevant events to your webhook through an API Destination.

1. Create a Connection (EventBridge -> API destinations -> Connections). Use Authorization type "API
   key". Set the API key name to your secret header (`X-Marketplace-Webhook-Secret`) and the value to the
   same string as `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET`. EventBridge adds this header to every
   delivery, which is what the package verifies. Requests without a matching secret are rejected with 401.

2. Create an API Destination pointing at your webhook, using that Connection:

   ```
   POST https://app.example.com/marketplace/aws/webhook
   ```

3. Create a Rule on the default bus with this event pattern:

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

4. Set the Rule's target to the API Destination. EventBridge delivers the full event JSON as the request
   body, which is exactly what the package parses; no input transformer is needed.

5. Configure a dead-letter queue on the target. EventBridge retries 5xx responses (so a transient failure
   in your listeners gets retried), but a 4xx such as a 401 from a misconfigured secret is not retried and
   would be lost without a DLQ.

About the role suffix: agreement and license events carry a role suffix in the detail-type, ` - Proposer`
for the seller of record and ` - Manufacturer` for the ISV. License events are always `- Manufacturer`;
if you are both proposer and manufacturer (a direct public or private offer) you receive the `- Proposer`
variant of the agreement events. The package strips the suffix when classifying, so either variant is
handled. Include the variants that match your listing in the rule.

## 8. Step 6: React to lifecycle events

### What the webhook does before your code runs

Every delivery flows through this pipeline in the controller, so your listeners receive clean domain
events:

1. Verify the shared secret (the middleware); reject with 401 if it is missing or wrong.
2. Parse the EventBridge payload into a normalized `AwsMarketplaceEvent`.
3. Deduplicate by the event's idempotency key (`detail.requestId`, falling back to the envelope `id`). A
   replayed delivery returns 200 without reprocessing.
4. Maintain the canonical subscription keyed by the agreement id, overlaying the event's fields onto the
   stored row without nulling what the event does not carry (so a License Updated fills in the LicenseArn
   without wiping the account id).
5. Dispatch the specific typed event (if the delivery maps to an actionable transition) and always
   dispatch the catch-all `AwsMarketplaceEventReceived`. Both carry the subscription.
6. Return 200 on success, or 500 if a listener threw, so EventBridge retries. On success the event is
   marked processed.

The typed events you can listen for:

| Event | Raised for | Key extras |
| --- | --- | --- |
| `SubscriptionActivated` | New agreement (Purchase Agreement Created, intent NEW) | - |
| `SubscriptionRenewed` | Renewal (Created, intent RENEW). New agreement and LicenseArn. | - |
| `SubscriptionReplaced` | Replacement (Created, intent REPLACE). New agreement and LicenseArn. | - |
| `SubscriptionUpdated` | Amendment or License Updated on the same agreement | `->changes` (entitlements hint) |
| `SubscriptionSuperseded` | Old agreement ended because renewed or replaced. Not a cancellation. | `->event->agreementStatus` (RENEWED / REPLACED) |
| `SubscriptionCancelled` | Agreement ended for real, or license deprovisioned | `->reason` (Cancelled / Expired / Terminated / Unknown) |
| `AwsMarketplaceEventReceived` | Every delivery, including non-actionable ones | `->subscription` may be null |

Every typed event exposes `->subscription` (the affected `Subscription`, keyed by `->id` = agreement id)
and `->event` (the normalized `AwsMarketplaceEvent` with the native AWS fields and the raw payload).

### A provisioning service

Put the business logic in one service so it is easy to test and the event wiring stays thin.

```php
// app/Marketplace/MarketplaceProvisioner.php
namespace App\Marketplace;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionRenewed;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionReplaced;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionSuperseded;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;

class MarketplaceProvisioner
{
    public function activate(SubscriptionActivated $e): void
    {
        // The agreement-created event has the agreement id and account id but not the LicenseArn yet, so
        // there is nothing to join to a landing-bound tenant here. The link is finalized on the License
        // Updated event (see updated()). Use this hook if you provision purely from events.
        Log::info('AWS agreement created', ['agreement' => $e->subscription->id]);
    }

    public function updated(SubscriptionUpdated $e): void
    {
        $tenant = $this->tenantFor($e->subscription);

        if ($tenant === null) {
            return; // Not ours yet, or a renewal handled by renewed().
        }

        $tenant->status = 'active';
        $tenant->current_period_end = $e->subscription->currentPeriodEnd;
        $tenant->save();

        // An amendment or a license update both re-provision entitlements. Re-sync (no-op for pure usage
        // products, which have no contract entitlements).
        $this->syncEntitlements($tenant, $e->subscription);
    }

    public function cancelled(SubscriptionCancelled $e): void
    {
        $tenant = Tenant::where('aws_agreement_id', $e->subscription->id)->first();

        if ($tenant === null) {
            return;
        }

        // Usage products: report any unreported usage before the one-hour final window closes.
        if ($tenant->isMetered() && $e->subscription->licenseArn !== null) {
            $this->reportFinalUsage($tenant, $e->subscription);
        }

        Log::info('AWS subscription cancelled', [
            'agreement' => $e->subscription->id,
            'reason' => $e->reason->value,
            'final_metering_deadline' => optional($e->event->finalMeteringDeadline)->toIso8601String(),
        ]);

        $tenant->status = 'cancelled';
        $tenant->plan = 'cancelled';
        $tenant->save();

        // Revoke access here (disable logins, stop background work, etc.).
    }

    public function superseded(SubscriptionSuperseded $e): void
    {
        // Renewed or replaced: do NOT revoke. The successor agreement arrives as SubscriptionRenewed or
        // SubscriptionReplaced and renewed() moves the tenant forward. This handler is for audit only;
        // keep it independent of renewed() because AWS does not guarantee their order.
        Log::info('AWS agreement superseded', [
            'agreement' => $e->subscription->id,
            'status' => $e->event->agreementStatus, // RENEWED or REPLACED
        ]);
    }

    public function renewed(SubscriptionRenewed $e): void
    {
        $this->reconcileSuccessor($e->subscription, $e->event->customerAccountId);
    }

    public function replaced(SubscriptionReplaced $e): void
    {
        $this->reconcileSuccessor($e->subscription, $e->event->customerAccountId);
    }
}
```

The linking helper that joins a landing-bound tenant (knows the LicenseArn) to the canonical subscription
(keyed by the agreement id). The key insight: the first event that carries both ids is the License
Updated event, so that is when the join happens. After that the tenant is matched by the agreement id.

```php
    /**
     * Resolve the tenant for a subscription, finalizing the landing link the first time the LicenseArn
     * and the agreement id are seen together.
     */
    private function tenantFor(Subscription $sub): ?Tenant
    {
        // 1. Already linked to this agreement.
        $tenant = Tenant::where('aws_agreement_id', $sub->id)->first();

        if ($tenant !== null) {
            if ($sub->licenseArn !== null && $tenant->aws_license_arn !== $sub->licenseArn) {
                $tenant->aws_license_arn = $sub->licenseArn; // keep the operational handle fresh
                $tenant->save();
            }

            return $tenant;
        }

        // 2. Bound at the landing by LicenseArn, not yet linked to an agreement (first purchase).
        if ($sub->licenseArn !== null) {
            $tenant = Tenant::where('aws_license_arn', $sub->licenseArn)
                ->whereNull('aws_agreement_id')
                ->first();

            if ($tenant !== null) {
                $tenant->aws_agreement_id = $sub->id;
                $tenant->save();

                return $tenant;
            }
        }

        return null;
    }

    /**
     * Point an existing customer at a renewal or replacement agreement, reconciled by the buyer account
     * id (AWS exposes no link from the new agreement to the old one).
     */
    private function reconcileSuccessor(Subscription $sub, ?string $accountId): void
    {
        $tenants = Tenant::where('aws_customer_account_id', $accountId)->get();

        if ($tenants->count() === 1) {
            $tenant = $tenants->first();
            $tenant->aws_agreement_id = $sub->id;                              // now tracks the new agreement
            $tenant->aws_license_arn = $sub->licenseArn ?? $tenant->aws_license_arn; // refreshed by License Updated if not yet present
            $tenant->status = 'active';
            $tenant->save();

            // Covers the ordering where the new agreement's License Updated arrived before this event;
            // guarded internally, so it is a no-op until the LicenseArn and product code are known.
            $this->syncEntitlements($tenant, $sub);

            return;
        }

        // 0 or many tenants for this account: a single AWS account can hold several agreements, so the
        // account id alone does not say which tenant this renewal continues, and no API can resolve it.
        // Surface it for a human decision rather than guessing.
        Log::warning('AWS Marketplace renewal needs manual reconciliation', [
            'agreement' => $sub->id,
            'account' => $accountId,
            'matched_tenants' => $tenants->count(),
        ]);
    }
```

The entitlement and final-usage helpers are shown in Steps 7 and 8.

### Wire the events

Register one subscriber that routes each event to the service.

```php
// app/Marketplace/MarketplaceEventSubscriber.php
namespace App\Marketplace;

use LMendes\LaravelAwsMarketplace\Events\SubscriptionActivated;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionCancelled;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionRenewed;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionReplaced;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionSuperseded;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;

class MarketplaceEventSubscriber
{
    public function __construct(private readonly MarketplaceProvisioner $provisioner) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            SubscriptionActivated::class => 'onActivated',
            SubscriptionUpdated::class => 'onUpdated',
            SubscriptionCancelled::class => 'onCancelled',
            SubscriptionSuperseded::class => 'onSuperseded',
            SubscriptionRenewed::class => 'onRenewed',
            SubscriptionReplaced::class => 'onReplaced',
        ];
    }

    public function onActivated(SubscriptionActivated $e): void { $this->provisioner->activate($e); }
    public function onUpdated(SubscriptionUpdated $e): void { $this->provisioner->updated($e); }
    public function onCancelled(SubscriptionCancelled $e): void { $this->provisioner->cancelled($e); }
    public function onSuperseded(SubscriptionSuperseded $e): void { $this->provisioner->superseded($e); }
    public function onRenewed(SubscriptionRenewed $e): void { $this->provisioner->renewed($e); }
    public function onReplaced(SubscriptionReplaced $e): void { $this->provisioner->replaced($e); }
}
```

```php
// app/Providers/AppServiceProvider.php
use App\Marketplace\MarketplaceEventSubscriber;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::subscribe(MarketplaceEventSubscriber::class);
}
```

### Make handlers idempotent

The package dedupes retried deliveries, but design your handlers to be safe if called more than once
anyway (the same logical state can be reached by different events, and you may replay events during
incident recovery). The example handlers are idempotent: they set state to a target value rather than
toggling it.

### Synchronous vs queued listeners (important)

By default your listeners run inside the webhook request. If one throws, the controller returns 500 and
EventBridge retries the delivery; that is the safety net.

If you make a listener implement `ShouldQueue`, dispatching returns immediately and the listener runs
later on the queue. The webhook then returns 200 and marks the event processed before your listener has
done its work, so:

- A failure in a queued listener does not trigger an EventBridge retry. You rely on Laravel's queue
  retries and `failed_jobs` instead.
- Configure queue retries, backoff, and failed-job alerting for that work.

Both models are valid. Use synchronous listeners for fast, must-not-be-lost work (let EventBridge retry),
or queued listeners for slow work (and own the retry story in Laravel). The event objects carry plain
readonly DTOs, so they serialize onto the queue cleanly.

## 9. Step 7: Entitlements (contract products)

For Contract and Contract-with-consumption products, fetch the buyer's entitlements and map them to your
plan and limits. The right time is when the link is finalized and whenever a `SubscriptionUpdated`
arrives (an amendment or license update re-provisions entitlements). The `updated()` handler above
already calls `syncEntitlements`. Here it is:

```php
    private function syncEntitlements(Tenant $tenant, Subscription $sub): void
    {
        if ($sub->productCode === null || $sub->licenseArn === null) {
            return;
        }

        $entitlements = AwsMarketplace::entitlements($sub->productCode, $sub->licenseArn);

        if ($entitlements === []) {
            return; // Pure usage product, or nothing entitled yet.
        }

        // Map AWS dimensions to your plan and limits. Adjust to match your listing's dimensions; here a
        // "seats" dimension carries a quantity and the tier dimensions select the plan.
        $planByDimension = [
            'starter_tier' => 'starter',
            'pro_tier' => 'pro',
            'enterprise_tier' => 'enterprise',
        ];

        foreach ($entitlements as $entitlement) {
            if ($entitlement->dimension === 'seats') {
                $tenant->seats = $entitlement->units;
            }

            if (isset($planByDimension[$entitlement->dimension]) && $entitlement->units > 0) {
                $tenant->plan = $planByDimension[$entitlement->dimension];
            }
        }

        $tenant->status = 'active';
        $tenant->save();
    }
```

Each `Entitlement` exposes:

| Property | Type | Notes |
| --- | --- | --- |
| `dimension` | `string` | The pricing dimension key from your listing. |
| `units` | `int` | The entitled quantity (AWS `Value.IntegerValue`). |
| `expiresAt` | `?CarbonInterface` | When the entitlement expires, if AWS provides it. |
| `raw` | `array` | The untouched AWS entitlement. |

`AwsMarketplace::entitlements($productCode, $licenseArn)` follows pagination and returns a flat
`list<Entitlement>`. You can also call it on demand (for example to re-check before granting a gated
feature), not only from the event handler.

## 10. Step 8: Metering (usage products)

For Pay-As-You-Go and Contract-with-consumption products, report metered usage with `AwsMarketplace::meter`.

```php
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;

$result = AwsMarketplace::meter(
    $licenseArn,
    $customerAccountId,
    new UsageRecord(dimension: 'requests', quantity: 1200),
    new UsageRecord(dimension: 'gb_processed', quantity: 34),
);

// $result->accepted  -> records AWS processed, each with a per-record Status
// $result->rejected  -> records AWS could not accept; retry these
// $result->raw       -> the full BatchMeterUsage response
```

How AWS counts, and the rules that follow from it:

- AWS meters each customer and dimension once per hour. Submit the hour's cumulative total in a single
  record. Submitting the same record again (a retry) is de-duplicated and not double-counted; submitting
  two different quantities for the same hour is not additive. Aggregate before you submit.
- Report every hour, and send a record with quantity 0 even when there was no usage, so AWS sees a
  heartbeat.
- `UsageRecord` defaults its timestamp to now; pass `timestamp:` to report a specific hour. AWS accepts
  timestamps only within a recent window (roughly the last few hours), so report promptly.
- One `meter()` call is one BatchMeterUsage call. Keep it to a single license's handful of dimensions
  (AWS caps a call at 25 records); call `meter()` once per tenant.

`accepted` holds AWS `Results` and `rejected` holds `UnprocessedRecords`. Inspect each accepted record's
`Status` (for example `Success`, `CustomerNotSubscribed`, `DuplicateRecord`) and retry anything in
`rejected`:

```php
foreach ($result->accepted as $record) {
    if (($record['Status'] ?? null) !== 'Success') {
        Log::warning('Metering record not successful', $record);
    }
}

if ($result->rejected !== []) {
    // Transient: retry now or on the next run. AWS de-duplicates, so resending is safe.
    Log::warning('Metering records unprocessed; will retry', ['count' => count($result->rejected)]);
}
```

### A scheduled hourly reporter

```php
// app/Console/Commands/ReportMarketplaceUsage.php
namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use LMendes\LaravelAwsMarketplace\DTO\UsageRecord;
use LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace;

class ReportMarketplaceUsage extends Command
{
    protected $signature = 'marketplace:report-usage';
    protected $description = 'Report the past hour of metered usage to AWS Marketplace';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('aws_license_arn')
            ->whereNotNull('aws_customer_account_id')
            ->get()
            ->filter->isMetered();

        foreach ($tenants as $tenant) {
            $usage = $tenant->usageForLastHour(); // your aggregation: ['requests' => 1200, 'gb_processed' => 34]

            $records = [];
            foreach ($usage as $dimension => $quantity) {
                $records[] = new UsageRecord(dimension: $dimension, quantity: (int) $quantity);
            }

            if ($records === []) {
                $records[] = new UsageRecord(dimension: 'requests', quantity: 0); // heartbeat
            }

            $result = AwsMarketplace::meter($tenant->aws_license_arn, $tenant->aws_customer_account_id, ...$records);

            if ($result->rejected !== []) {
                $this->warn("Unprocessed records for tenant {$tenant->getKey()}; will retry next run.");
            }
        }

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('marketplace:report-usage')->hourly();
```

### The final metering window on cancellation

When an agreement ends, AWS allows one last metered submission within one hour. The `cancelled()` handler
surfaces the deadline as `$e->event->finalMeteringDeadline` and reports the final partial usage. Do it
promptly (synchronously, or as an immediate queued job, not a delayed one) so it lands before the window
closes:

```php
    private function reportFinalUsage(Tenant $tenant, Subscription $sub): void
    {
        $usage = $tenant->usageForLastHour();

        $records = [];
        foreach ($usage as $dimension => $quantity) {
            $records[] = new UsageRecord(dimension: $dimension, quantity: (int) $quantity);
        }

        if ($records !== []) {
            AwsMarketplace::meter($sub->licenseArn, $tenant->aws_customer_account_id, ...$records);
        }
    }
```

Use `$sub->licenseArn` (the persisted subscription) for the final meter: the Purchase Agreement Ended
event does not carry the LicenseArn, but the stored subscription does. After the deadline AWS rejects
late usage.

## 11. Renewals and replacements

A renewal or replacement is a new agreement with a new LicenseArn, so you receive a new subscription, not
an update to the old one. The sequence:

- `SubscriptionSuperseded` for the OLD agreement (Purchase Agreement Ended, status RENEWED or REPLACED).
  Its subscription is marked `superseded`. This is not a cancellation: do not revoke access.
- `SubscriptionRenewed` (intent RENEW) or `SubscriptionReplaced` (intent REPLACE) for the NEW agreement.
  `->subscription->id` is the new agreement id.
- a `SubscriptionUpdated` (License Updated) carrying the new LicenseArn.

These arrive on different subscriptions (old and new agreement ids) with no ordering guarantee, so the
`superseded()` and `renewed()`/`replaced()` handlers in Step 6 are independent and idempotent. The
`reconcileSuccessor` helper moves the existing tenant onto the new agreement by the buyer account id, and
`tenantFor` refreshes the new LicenseArn whenever the License Updated arrives, so the two orderings (New
agreement event first, or License Updated first) both converge on the same result.

The unavoidable caveat: a single AWS account can hold more than one agreement for the same product (this
is what Concurrent Agreements enables), which in your app can mean more than one tenant per account. When
that happens the buyer account id alone does not identify which tenant a renewal continues, and neither
the library nor any AWS API can resolve it (AWS emits no link). `reconcileSuccessor` logs that case for a
human decision (for example, asking an admin from that account to confirm) instead of guessing.

## 12. What to wire per product type

| Product type | Landing | Entitlements | Metering | Key events |
| --- | --- | --- | --- | --- |
| Pay-As-You-Go (usage) | yes | no | yes (hourly) | Activated, Updated (link), Cancelled (final-meter window) |
| Contract | yes | yes (on Updated) | no | Activated, Updated, Cancelled |
| Contract with consumption | yes | yes (on Updated) | yes (overage) | Activated, Updated, Cancelled |

Renewals and replacements apply to all three. The same provisioner handles every type; entitlement sync
is a no-op when there are no contract entitlements, and you only schedule the metering command for usage
products.

## 13. Persistence

Subscriptions are stored in `aws_marketplace_subscriptions`, keyed by `agreement_id`:

| Column | Notes |
| --- | --- |
| `agreement_id` | Unique. The canonical key. |
| `license_arn` | Nullable, indexed. Filled in from the License Updated event. |
| `product_code` | Nullable. |
| `customer_account_id` | Nullable, indexed. |
| `customer_identifier` | Nullable. |
| `status` | `pending_fulfillment`, `active`, `superseded`, `unsubscribed`. |
| `current_period_end` | Nullable timestamp. |
| `owner_type` / `owner_id` | Nullable polymorphic owner (see below). |
| `raw` | The raw payload as JSON. |

Idempotency receipts live in `aws_marketplace_processed_events` (`event_key` unique, `processed_at`).

What you can do with persistence:

- Use your own columns (this guide's approach). Store the AWS ids on your tenant and treat the package's
  table as an internal record. Look up the canonical row when you need it:
  `app(SubscriptionRepository::class)->findByLicenseArn($licenseArn)` or `->find($agreementId)`.

- Or attach your tenant through the `owner` morph. The `AwsSubscription` model has `owner()`, and the
  package's `save()` never clobbers an owner you set, so it survives later webhook updates. Attach it once
  the subscription exists (for example in `updated()`), and add the inverse relation to your tenant:

  ```php
  use LMendes\LaravelAwsMarketplace\Models\AwsSubscription;

  $awsSubscription = AwsSubscription::where('agreement_id', $sub->id)->first();
  $awsSubscription->owner()->associate($tenant)->save();

  // app/Models/Tenant.php
  public function awsSubscriptions(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
      return $this->morphMany(AwsSubscription::class, 'owner');
  }
  ```

- Swap the models via `marketplace-aws.persistence.model` and
  `marketplace-aws.persistence.idempotency.model`. Extend `AwsSubscription` rather than only replicating
  its columns: the default `EloquentSubscriptionRepository` reads the row through that model's casts
  (`status` to the `SubscriptionStatus` enum, `current_period_end` to a date, `raw` to an array). To map a
  different schema entirely, bind your own `SubscriptionRepository` / `ProcessedEventStore`.

- Disable persistence with `marketplace-aws.persistence.enabled = false` to manage state yourself. The
  webhook still dispatches events, but without dedup (at-least-once) and without a stored subscription
  (the events carry a transient subscription built from the payload).

- Prune idempotency receipts. The dedup table grows one row per event and is never pruned automatically.
  Set a retention window and schedule the prune command:

  ```dotenv
  AWS_MARKETPLACE_IDEMPOTENCY_TTL_DAYS=30
  ```

  ```php
  // routes/console.php
  use Illuminate\Support\Facades\Schedule;

  Schedule::command('aws-marketplace:prune-events')->daily();
  ```

  Receipts only need to outlive the EventBridge retry window, so a generous TTL (for example 30 days) is
  safe; pruning an old receipt only re-enables dedup for an event that is effectively never resent. Pass
  `--days=` to override the configured TTL for a one-off run.

## 14. Local testing

### Simulate a webhook

A delivery is a JSON POST with the secret header. The package parses the standard EventBridge envelope,
so you can replay realistic payloads with curl. New agreement:

```bash
curl -X POST https://app.example.com/marketplace/aws/webhook \
  -H 'Content-Type: application/json' \
  -H 'X-Marketplace-Webhook-Secret: replace-with-a-long-random-string' \
  -d '{
        "detail-type": "Purchase Agreement Created - Proposer",
        "source": "aws.agreement-marketplace",
        "detail": {
          "requestId": "test-created-1",
          "agreement": { "id": "agmt-123", "intent": "NEW" },
          "acceptor": { "accountId": "111122223333" }
        }
      }'
```

License Updated (this is the delivery that fills in the LicenseArn and finalizes the link):

```bash
curl -X POST https://app.example.com/marketplace/aws/webhook \
  -H 'Content-Type: application/json' \
  -H 'X-Marketplace-Webhook-Secret: replace-with-a-long-random-string' \
  -d '{
        "detail-type": "License Updated - Manufacturer",
        "source": "aws.agreement-marketplace",
        "detail": {
          "requestId": "test-license-1",
          "agreement": { "id": "agmt-123" },
          "license": { "arn": "arn:aws:license-manager::111122223333:license/l-abc" },
          "product": { "code": "your-product-code" },
          "acceptor": { "accountId": "111122223333" }
        }
      }'
```

Cancellation (status CANCELLED), and a renewal pair (Ended RENEWED for the old agreement, plus Created
RENEW for the new one) follow the same shape; change `detail-type` and the `agreement.status` or
`agreement.intent`. See the reference table in Step 17 for every combination.

### Drive the landing without AWS

The landing calls ResolveCustomer (a real AWS call), so for a local end-to-end test bind a fake resolver
or mock the metering client, the way the package's own tests do:

```php
use Aws\MarketplaceMetering\MarketplaceMeteringClient;
use Mockery;

$client = Mockery::mock(MarketplaceMeteringClient::class);
$client->shouldReceive('resolveCustomer')
    ->with(['RegistrationToken' => 'tok'])
    ->andReturn([
        'LicenseArn' => 'arn:aws:license-manager::111122223333:license/l-abc',
        'CustomerAWSAccountId' => '111122223333',
        'CustomerIdentifier' => 'cust-1',
        'ProductCode' => 'your-product-code',
    ]);
$this->app->instance(MarketplaceMeteringClient::class, $client);

$this->post('/marketplace/aws/landing', ['x-amzn-marketplace-token' => 'tok'])
    ->assertRedirect();
```

### Test your provisioning

Your handlers listen for plain Laravel events, so test them by dispatching the typed event and asserting
the tenant changed:

```php
use App\Models\Tenant;
use LMendes\LaravelAwsMarketplace\DTO\AwsMarketplaceEvent;
use LMendes\LaravelAwsMarketplace\DTO\Subscription;
use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;
use LMendes\LaravelAwsMarketplace\Enums\EventType;
use LMendes\LaravelAwsMarketplace\Enums\SubscriptionStatus;
use LMendes\LaravelAwsMarketplace\Events\SubscriptionUpdated;

$tenant = Tenant::factory()->create([
    'aws_license_arn' => 'arn-1',
    'aws_agreement_id' => null,
    'status' => 'pending',
]);

$sub = new Subscription(
    id: 'agmt-1',
    licenseArn: 'arn-1',
    productCode: 'your-product-code',
    customerAccountId: '111122223333',
    status: SubscriptionStatus::Active,
);

event(new SubscriptionUpdated(
    $sub,
    new AwsMarketplaceEvent(type: EventType::Updated, detailType: 'License Updated - Manufacturer', licenseArn: 'arn-1'),
    new SubscriptionChanges([SubscriptionChanges::ENTITLEMENTS]),
));

$tenant->refresh();
expect($tenant->aws_agreement_id)->toBe('agmt-1');
expect($tenant->status)->toBe('active');
```

### End-to-end against real AWS

Point the listing fulfillment URL and the EventBridge API Destination at a public tunnel (for example
`https://<your-id>.ngrok.app/marketplace/aws/landing` and `.../webhook`), subscribe with a test buyer,
and watch the landing redirect and the events arrive.

## 15. Production checklist

- HTTPS on both routes; a stable hostname matching the listing and EventBridge configuration.
- A long random `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET`, stored as a secret, matching the
  EventBridge Connection. Rotate by updating both sides.
- Least-privilege IAM for the three Marketplace actions, plus `sts:AssumeRole` only if you assume a
  seller role.
- A dead-letter queue on the EventBridge target to catch deliveries that exhaust retries or are rejected.
- A decision per listener on synchronous vs queued (Step 6), with queue retries and failed-job alerting
  if queued.
- For usage products: the hourly `marketplace:report-usage` schedule, with monitoring on
  `$result->rejected`.
- `AWS_MARKETPLACE_IDEMPOTENCY_TTL_DAYS` set and `aws-marketplace:prune-events` scheduled.
- Monitoring on webhook 401s (secret drift) and 500s (listener failures), and on the manual-reconciliation
  warning from renewals.

## 16. Troubleshooting

| Symptom | Likely cause and fix |
| --- | --- |
| Webhook returns 401 | The secret or header does not match. Confirm `AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET` and `..._HEADER` equal what the EventBridge Connection sends. |
| Landing 419 (CSRF) | The landing is under the `web` group with CSRF on. Use the signed-redirect pattern (Step 4) or except `marketplace/aws/landing` from CSRF. |
| `Auth::login()` at the landing does not stick | The landing is stateless (`api`), so there is no session. Log in on the signed `web` claim route instead (Step 4). |
| `licenseArn` is null | Only license events carry it. Wait for the License Updated event, or read it from the persisted subscription rather than the agreement event. |
| Tenant never links to its agreement | The link is finalized on License Updated. Confirm that event reaches the webhook and that the tenant's `aws_license_arn` matches the event's. |
| Entitlements come back empty | Usage-only product (expected), wrong `productCode`/`licenseArn`, or called before License Updated. Re-check after the Updated event. |
| Metering records in `rejected` | Transient AWS issue; retry (AWS de-duplicates). Records with `CustomerNotSubscribed`/`DuplicateRecord` show up in `accepted` with that `Status`, not in `rejected`. |
| Duplicate provisioning | Make handlers idempotent (Step 6). The package dedupes deliveries, but the same state can arrive via different events. |
| Access revoked on renewal | You revoked on `SubscriptionSuperseded`. That is not a cancellation; do not revoke there (Step 11). |
| `AccessDenied` on a Marketplace call | Missing IAM action, or the credentials are not for the seller account. Add the action, set the dedicated `AWS_MARKETPLACE_ACCESS_KEY_ID` / `AWS_MARKETPLACE_SECRET_ACCESS_KEY`, or set `AWS_MARKETPLACE_ROLE_ARN`. |

## 17. Reference

### Events table

All events use source `aws.agreement-marketplace`; the detail-type ends with ` - Proposer` or
` - Manufacturer` (the package strips the suffix).

| detail-type | detail.agreement.intent / status | Dispatched event | Carries LicenseArn |
| --- | --- | --- | --- |
| Purchase Agreement Created | intent NEW | `SubscriptionActivated` | no |
| Purchase Agreement Created | intent RENEW | `SubscriptionRenewed` | no |
| Purchase Agreement Created | intent REPLACE | `SubscriptionReplaced` | no |
| Purchase Agreement Amended | intent AMEND | `SubscriptionUpdated` | no |
| License Updated | - | `SubscriptionUpdated` | yes |
| Purchase Agreement Ended | status CANCELLED / EXPIRED / TERMINATED | `SubscriptionCancelled` | no |
| Purchase Agreement Ended | status RENEWED / REPLACED | `SubscriptionSuperseded` | no |
| License Deprovisioned | - | `SubscriptionCancelled` | yes |

Any other delivery maps to `EventType::Unknown`: it is persisted as nothing and surfaced only through
`AwsMarketplaceEventReceived` (with a null subscription).

### AwsMarketplaceEvent fields

Available on every dispatched event as `$event->event`:

| Field | Type | Notes |
| --- | --- | --- |
| `type` | `EventType` | Normalized classification. |
| `detailType` | `string` | The raw detail-type, suffix included. |
| `licenseArn` | `?string` | Present on license events only. |
| `agreementId` | `?string` | On every SaaS event. |
| `customerAccountId` | `?string` | Buyer AWS account id. |
| `productCode` | `?string` | Your product code. |
| `intent` | `?string` | NEW / RENEW / REPLACE / AMEND (agreement-created/amended). |
| `agreementStatus` | `?string` | CANCELLED / EXPIRED / TERMINATED / RENEWED / REPLACED (agreement-ended). |
| `idempotencyKey` | `?string` | detail.requestId, falling back to the envelope id. |
| `currentPeriodEnd` | `?CarbonInterface` | Parsed agreement end time. |
| `finalMeteringDeadline` | `?CarbonInterface` | One hour after a termination; null otherwise. |
| `cancellationReason` | `?CancellationReason` | Set only on a real termination. |
| `changes` | `list<string>` | `['entitlements']` on amendment / license update. |
| `raw` | `array` | The untouched EventBridge payload. |

### Enums

- `EventType`: `Activated`, `Renewed`, `Replaced`, `Updated`, `Superseded`, `Unsubscribed`, `Unknown`.
- `SubscriptionStatus`: `PendingFulfillment`, `Active`, `Superseded`, `Unsubscribed`.
- `CancellationReason`: `Cancelled`, `Expired`, `Terminated`, `Unknown`.

### Facade

`LMendes\LaravelAwsMarketplace\Facades\AwsMarketplace`:

| Method | Returns | Use |
| --- | --- | --- |
| `resolve(string $registrationToken)` | `ResolvedCustomer` | Exchange a landing token (the controller does this for you). |
| `entitlements(string $productCode, string $licenseArn)` | `list<Entitlement>` | Fetch contract entitlements (paginated). |
| `meter(string $licenseArn, string $customerAccountId, UsageRecord ...$records)` | `MeterResult` | Report metered usage. |

### Contracts you can implement or bind

- `FulfillmentHandler` (`fulfilled(ResolvedCustomer): Response`, `failed(Throwable): Response`) - required;
  bind it.
- `SubscriptionRepository` (`find`, `findByLicenseArn`, `save`) - swap to use your own subscription
  storage.
- `ProcessedEventStore` (`isProcessed`, `markProcessed`, `prune`) - swap to use your own dedup storage.

### Routes and commands

- `GET|POST marketplace/aws/landing` (name `marketplace.aws.landing`) - the fulfillment URL.
- `POST marketplace/aws/webhook` (name `marketplace.aws.webhook`) - the EventBridge target, secret-verified.
- `php artisan aws-marketplace:install [--no-migrate] [--force]` - publish config and migrations, migrate.
- `php artisan aws-marketplace:prune-events [--days=]` - prune idempotency receipts past the TTL.
