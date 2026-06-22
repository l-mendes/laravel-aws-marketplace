# Changelog

All notable changes to `l-mendes/laravel-aws-marketplace` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-06-22

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

[Unreleased]: https://github.com/l-mendes/laravel-aws-marketplace/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/l-mendes/laravel-aws-marketplace/releases/tag/v1.0.0
