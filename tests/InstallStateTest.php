<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Updates\InstallState;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use wpdb;

class InstallStateFakeSchema
{
    public const SCHEMA_VERSION = 3;

    public static int $runs = 0;

    public static function ensureInstalled(): void
    {
        self::$runs++;
    }
}

class InstallStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wpdb'] = new wpdb();
        InstallStateFakeSchema::$runs = 0;
    }

    public function testChecksRunOnceThenTheAutoloadedRecordShortCircuitsThem(): void
    {
        InstallState::ensure([InstallStateFakeSchema::class]);
        $this->assertSame(1, InstallStateFakeSchema::$runs);
        $this->assertNotSame('', Secret::get());
        $this->assertSame(['secret' => true, 'schemas' => [InstallStateFakeSchema::class => 3]], get_option(InstallState::OPTION));

        InstallState::ensure([InstallStateFakeSchema::class]);
        InstallState::ensure([InstallStateFakeSchema::class]);
        $this->assertSame(1, InstallStateFakeSchema::$runs);
    }

    public function testASchemaBumpRunsTheChecksAgain(): void
    {
        update_option(InstallState::OPTION, ['secret' => true, 'schemas' => [InstallStateFakeSchema::class => 2]]);

        InstallState::ensure([InstallStateFakeSchema::class]);

        $this->assertSame(1, InstallStateFakeSchema::$runs);
        $this->assertSame(3, get_option(InstallState::OPTION)['schemas'][InstallStateFakeSchema::class]);
    }

    public function testLastContactIsWrittenAtMostOnceAMinute(): void
    {
        $secret = Secret::ensure();
        $option = 'clockwork_companion_last_contact_at';
        update_option($option, time() - 30);
        $before = get_option($option);

        $ts = time();
        $request = new WP_REST_Request('GET', '/clockwork/v1/health', [], [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => hash_hmac('sha256', "GET\n/wp-json/clockwork/v1/health\n{$ts}\n", $secret),
        ]);
        $this->assertTrue(HmacVerifier::verify($request));
        $this->assertSame($before, get_option($option));

        update_option($option, time() - 61);
        $again = new WP_REST_Request('GET', '/clockwork/v1/health', [], [
            'x-clockwork-timestamp' => (string) $ts,
            'x-clockwork-signature' => hash_hmac('sha256', "GET\n/wp-json/clockwork/v1/health\n{$ts}\n", $secret),
        ]);
        $this->assertTrue(HmacVerifier::verify($again));
        $this->assertGreaterThanOrEqual(time() - 1, get_option($option));
    }
}
