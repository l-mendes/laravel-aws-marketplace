<?php

namespace LMendes\LaravelAwsMarketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authenticates an inbound AWS Marketplace webhook. EventBridge reaches the app through an API
 * Destination that signs each request with a shared secret header; there is no AWS request signature to
 * verify, so the secret is the authentication. A missing, unconfigured, or mismatching secret is
 * rejected before the controller runs.
 */
class VerifyEventBridgeWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('marketplace-aws.eventbridge.webhook_secret');
        $header = config('marketplace-aws.eventbridge.webhook_secret_header', 'X-Marketplace-Webhook-Secret');
        $provided = $request->header($header);

        if (! is_string($secret) || $secret === '' || ! is_string($provided) || ! hash_equals($secret, $provided)) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
