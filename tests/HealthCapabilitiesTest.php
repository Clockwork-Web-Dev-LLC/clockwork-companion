<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\HealthRoute;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class HealthCapabilitiesTest extends TestCase
{
    public function testCodeSnippetsAdvertisedByDefault(): void
    {
        $this->assertContains('code-snippets', HealthRoute::capabilities());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCodeSnippetsNotAdvertisedWhenDisabled(): void
    {
        define('CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS', true);

        $this->assertNotContains('code-snippets', HealthRoute::capabilities());
        $this->assertContains('snapshot', HealthRoute::capabilities());
    }
}
