# Changelog

All notable changes to `l-mendes/laravel-aws-marketplace` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Dedicated seller-account credentials for the Marketplace API clients via `AWS_MARKETPLACE_ACCESS_KEY_ID`,
  `AWS_MARKETPLACE_SECRET_ACCESS_KEY`, and `AWS_MARKETPLACE_SESSION_TOKEN` (config `credentials.*`). They
  are scoped to the Marketplace clients and never touch the global `AWS_ACCESS_KEY_ID` /
  `AWS_SECRET_ACCESS_KEY`, so a separate Marketplace account no longer collides with the credentials the
  host app uses for S3, SES, SQS, and so on. When a seller-role ARN is also configured, these credentials
  become the source identity that assumes it. Defaults preserve the previous behaviour (default AWS
  credential chain).

## [0.1.0] - 2026-06-22

### Added

- First release: a standalone AWS Marketplace integration for Laravel SaaS products, targeting the
  EventBridge and Concurrent Agreements model (source `aws.agreement-marketplace`).
- Customer resolution from the post-subscribe registration token via a `FulfillmentHandler`
  (ResolveCustomer).
- Contract entitlements (GetEntitlements, paginated) and metered usage (BatchMeterUsage).
- Lifecycle webhook with shared-secret verification, idempotent delivery handling, and typed domain
  events: `SubscriptionActivated`, `SubscriptionRenewed`, `SubscriptionReplaced`, `SubscriptionUpdated`,
  `SubscriptionSuperseded`, `SubscriptionCancelled`, plus the catch-all `AwsMarketplaceEventReceived`.
- Eloquent persistence for subscriptions and processed-event receipts, with swappable models and
  repositories and a polymorphic owner.
- Console commands `aws-marketplace:install` and `aws-marketplace:prune-events`.

[Unreleased]: https://github.com/l-mendes/laravel-aws-marketplace/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/l-mendes/laravel-aws-marketplace/releases/tag/v0.1.0
