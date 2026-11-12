<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Compat\ElementorCacheGuard;
use PHPUnit\Framework\TestCase;

class ElementorCacheGuardTest extends TestCase
{
    /**
     * The guard runs on every Elementor save; its failures are only logged
     * when the site enabled WP_DEBUG_LOG (as in Renegade), not always.
     */
    public function testFailuresAreNotLoggedWithoutWpDebugLog(): void
    {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $this->markTestSkipped('WP_DEBUG_LOG is enabled in this process');
        }

        $GLOBALS['wp_test_actions']['elementor/document/after_save'] = [];
        ElementorCacheGuard::register();
        $callback = $GLOBALS['wp_test_actions']['elementor/document/after_save'][0];

        $log = tempnam(sys_get_temp_dir(), 'cwk_log_');
        $previous = ini_set('error_log', $log);
        try {
            $callback(new class {
                public function get_main_id(): int
                {
                    throw new \RuntimeException('document exploded');
                }
            });
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('', (string) file_get_contents($log));
        @unlink($log);
    }
}
