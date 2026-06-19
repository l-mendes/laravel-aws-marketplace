<?php

namespace LMendes\LaravelAwsMarketplace\Tests\Unit\DTO;

use LMendes\LaravelAwsMarketplace\DTO\SubscriptionChanges;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SubscriptionChangesTest extends TestCase
{
    #[Test]
    public function an_empty_change_set_reports_empty_and_has_nothing(): void
    {
        $changes = new SubscriptionChanges;

        $this->assertTrue($changes->isEmpty());
        $this->assertFalse($changes->has(SubscriptionChanges::ENTITLEMENTS));
    }

    #[Test]
    public function a_populated_change_set_reports_what_it_holds(): void
    {
        $changes = new SubscriptionChanges([SubscriptionChanges::ENTITLEMENTS]);

        $this->assertFalse($changes->isEmpty());
        $this->assertTrue($changes->has(SubscriptionChanges::ENTITLEMENTS));
        $this->assertFalse($changes->has('quantity'));
    }
}
