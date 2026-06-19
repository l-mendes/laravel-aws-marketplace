<?php

use LMendes\LaravelAwsMarketplace\Models\AwsProcessedEvent;
use LMendes\LaravelAwsMarketplace\Models\AwsSubscription;

return [

    // The region the AWS Marketplace APIs (ResolveCustomer, GetEntitlements, BatchMeterUsage) are
    // called in.
    'region' => env('AWS_MARKETPLACE_REGION', 'us-east-1'),

    // The Marketplace APIs are authorized against the registered seller account, which may differ from
    // the account hosting the app. When an ARN is set, the app assumes this seller-account role for
    // those calls; left empty, the default SDK credentials are used (single-account / local).
    'role' => [
        'arn' => env('AWS_MARKETPLACE_ROLE_ARN'),
        'external_id' => env('AWS_MARKETPLACE_ROLE_EXTERNAL_ID'),
        'session_name' => env('AWS_MARKETPLACE_ROLE_SESSION_NAME', 'laravel-aws-marketplace'),
    ],

    // AWS Marketplace publishes agreement and license events (source aws.agreement-marketplace) to
    // EventBridge. A rule forwards them to the webhook through an API Destination that signs the request
    // with a shared secret header, which this package verifies with hash_equals.
    'eventbridge' => [
        'webhook_secret' => env('AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_SECRET'),
        'webhook_secret_header' => env('AWS_MARKETPLACE_EVENTBRIDGE_WEBHOOK_HEADER', 'X-Marketplace-Webhook-Secret'),
    ],

    // Subscriptions are persisted keyed by their agreement id; idempotency records dedupe retried
    // EventBridge deliveries. Disable either to manage that state yourself; swap the models to use your
    // own schema.
    'persistence' => [
        'enabled' => true,
        'model' => AwsSubscription::class,
        'table' => 'aws_marketplace_subscriptions',

        'idempotency' => [
            'enabled' => true,
            'model' => AwsProcessedEvent::class,
            'table' => 'aws_marketplace_processed_events',

            // Days to keep processed-event receipts. null keeps them forever; set a value and run
            // aws-marketplace:prune-events (for example on a daily schedule) to bound the table.
            'ttl' => env('AWS_MARKETPLACE_IDEMPOTENCY_TTL_DAYS'),
        ],
    ],

    // The landing (listing fulfillment URL) and webhook (EventBridge target) routes.
    'routes' => [
        'enabled' => true,
        'prefix' => 'marketplace/aws',
        'middleware' => ['api'],
        'name_prefix' => 'marketplace.aws.',
        'landing' => ['uri' => 'landing', 'methods' => ['GET', 'POST'], 'middleware' => []],
        'webhook' => ['uri' => 'webhook', 'middleware' => []],
    ],
];
