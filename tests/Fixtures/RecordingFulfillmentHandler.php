<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Fixtures;

use Illuminate\Http\Response;
use LMendes\LaravelAwsMarketplace\Contracts\FulfillmentHandler;
use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * An in-memory fulfillment handler that records what it received, for exercising the landing flow.
 */
class RecordingFulfillmentHandler implements FulfillmentHandler
{
    public ?ResolvedCustomer $fulfilledWith = null;

    public ?Throwable $failedWith = null;

    public function fulfilled(ResolvedCustomer $customer): SymfonyResponse
    {
        $this->fulfilledWith = $customer;

        return new Response('onboarding', 200);
    }

    public function failed(Throwable $exception): SymfonyResponse
    {
        $this->failedWith = $exception;

        return new Response('failed', 422);
    }
}
