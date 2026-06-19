<?php

namespace LMendes\LaravelAwsMarketplace\Http\Controllers;

use Illuminate\Http\Request;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\Services\AwsResolveCustomerService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * AWS Marketplace POSTs the buyer's browser here after they subscribe, with an
 * `x-amzn-marketplace-token`. The token is resolved into the buyer and license (ResolveCustomer) and
 * handed to the application's fulfillment handler, which owns onboarding, the redirect response, and
 * binding the buyer to a tenant. The canonical subscription is maintained separately from the lifecycle
 * webhook events.
 */
class HandleLandingController
{
    public function __construct(
        private readonly AwsResolveCustomerService $resolver,
        private readonly FulfillmentHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $token = $request->input('x-amzn-marketplace-token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Missing AWS Marketplace registration token.');
            }

            return $this->handler->fulfilled($this->resolver->resolve($token));
        } catch (Throwable $exception) {
            return $this->handler->failed($exception);
        }
    }
}
