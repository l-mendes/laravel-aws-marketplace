<?php

namespace LMendes\LaravelAwsMarketplace\Contracts;

use LMendes\LaravelAwsMarketplace\DTO\ResolvedCustomer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The consuming app implements this to handle the AWS Marketplace landing redirect. Bind it through the
 * container so the landing controller can resolve it.
 */
interface FulfillmentHandler
{
    /**
     * Handle a customer resolved from the landing redirect (post-subscribe). Returns the response sent
     * back to the buyer's browser, typically a redirect into onboarding. This is where you create or
     * link your tenant to the buyer and store the licenseArn and customer account id; the canonical
     * subscription is then maintained from the lifecycle webhook events and joins back through the
     * licenseArn.
     */
    public function fulfilled(ResolvedCustomer $customer): Response;

    /**
     * Handle a failed fulfillment (a missing, invalid, or expired token, or a resolve error).
     */
    public function failed(Throwable $exception): Response;
}
