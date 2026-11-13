<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\CoreUpdateRoute;
use PHPUnit\Framework\TestCase;

class CoreUpdateSelectionTest extends TestCase
{
    private function offer(string $response, string $version): object
    {
        return (object) ['response' => $response, 'version' => $version];
    }

    /** What update_core typically holds for a site on an older branch. */
    private function offers(): array
    {
        return [
            $this->offer('upgrade', '6.9.1'),
            $this->offer('autoupdate', '6.9.1'),
            $this->offer('autoupdate', '6.8.4'),
            $this->offer('development', '7.0-beta1'),
        ];
    }

    public function testOlderBranchGetsItsOwnMinorReleaseWithoutConfirmation(): void
    {
        $chosen = CoreUpdateRoute::selectUpdate($this->offers(), '6.8.2', false);

        $this->assertSame('6.8.4', $chosen->version);
        $this->assertFalse(CoreUpdateRoute::isMajorBump('6.8.2', $chosen->version));
    }

    public function testConfirmedMajorTakesTheNewestOffer(): void
    {
        $chosen = CoreUpdateRoute::selectUpdate($this->offers(), '6.8.2', true);

        $this->assertSame('6.9.1', $chosen->version);
        $this->assertSame('autoupdate', $chosen->response);
    }

    public function testBranchWithNothingNewerFallsBackToTheMajorOffer(): void
    {
        $chosen = CoreUpdateRoute::selectUpdate($this->offers(), '6.8.4', false);

        $this->assertSame('6.9.1', $chosen->version);
        $this->assertTrue(CoreUpdateRoute::isMajorBump('6.8.4', '6.9.1'));
    }

    public function testMajorIsJudgedOnXy(): void
    {
        $this->assertTrue(CoreUpdateRoute::isMajorBump('6.8.3', '6.9'));
        $this->assertTrue(CoreUpdateRoute::isMajorBump('6.9.4', '7.0'));
        $this->assertFalse(CoreUpdateRoute::isMajorBump('6.9', '6.9.1'));
        $this->assertFalse(CoreUpdateRoute::isMajorBump('6.9.1', '6.9.10'));
    }

    public function testDevelopmentAndOlderOffersAreIgnored(): void
    {
        $this->assertNull(CoreUpdateRoute::selectUpdate([
            $this->offer('development', '7.0-beta1'),
            $this->offer('latest', '6.9.1'),
            $this->offer('autoupdate', '6.9.1'),
        ], '6.9.1', true));
    }
}
