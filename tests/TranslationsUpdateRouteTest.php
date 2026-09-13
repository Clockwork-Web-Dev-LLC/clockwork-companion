<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\SnapshotRoute;
use ClockworkCompanion\Rest\TranslationsUpdateRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class TranslationsUpdateRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['wp_test_translation_updates'] = [];
        unset($GLOBALS['wp_test_bulk_upgrade_result']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_translation_updates'] = [];
        unset($GLOBALS['wp_test_bulk_upgrade_result']);
        parent::tearDown();
    }

    public function testRegisterRegistersRoute(): void
    {
        $route = new TranslationsUpdateRoute();
        $route->register();

        $this->assertArrayHasKey(
            CLOCKWORK_COMPANION_NAMESPACE . '/translations/update',
            $GLOBALS['wp_test_routes']
        );
    }

    public function testHandleReturnsUpToDateWhenNoTranslationsPending(): void
    {
        $GLOBALS['wp_test_translation_updates'] = [];

        $route = new TranslationsUpdateRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/translations/update');

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['updated_count']);
        $this->assertContains('Translations are already up to date.', $data['messages']);
    }

    public function testHandleUpgradesPendingTranslations(): void
    {
        $GLOBALS['wp_test_translation_updates'] = [
            (object) [
                'type'     => 'core',
                'slug'     => 'default',
                'language' => 'es_ES',
                'version'  => '6.7',
                'updated'  => '2026-09-01',
                'package'  => 'https://downloads.wordpress.org/translation/core/6.7/es_ES.zip',
            ],
            (object) [
                'type'     => 'plugin',
                'slug'     => 'akismet',
                'language' => 'es_ES',
                'version'  => '5.4',
                'updated'  => '2026-09-01',
                'package'  => 'https://downloads.wordpress.org/translation/plugin/akismet/5.4/es_ES.zip',
            ],
        ];
        $GLOBALS['wp_test_bulk_upgrade_result'] = [true, true];

        $route = new TranslationsUpdateRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/translations/update');

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertTrue($data['ok']);
        $this->assertSame(2, $data['updated_count']);
    }

    public function testHandleFailsWhenBulkUpgradeReturnsError(): void
    {
        $GLOBALS['wp_test_translation_updates'] = [
            (object) [
                'type'     => 'core',
                'slug'     => 'default',
                'language' => 'es_ES',
            ],
        ];
        $GLOBALS['wp_test_bulk_upgrade_result'] = false;

        $route = new TranslationsUpdateRoute();
        $request = new WP_REST_Request('POST', '/clockwork-companion/v1/translations/update');

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('upgrade_failed', $data['error']);
    }

    public function testSnapshotIncludesTranslationsPayload(): void
    {
        $GLOBALS['wp_test_translation_updates'] = [
            (object) [
                'type'     => 'core',
                'slug'     => 'default',
                'language' => 'fr_FR',
                'version'  => '6.7',
                'updated'  => '2026-09-01',
                'package'  => 'https://example.com/fr_FR.zip',
            ],
        ];

        $route = new SnapshotRoute();
        $request = new WP_REST_Request('GET', '/clockwork-companion/v1/snapshot');

        $response = $route->handle($request);
        $data = $response->get_data();

        $this->assertArrayHasKey('translations', $data);
        $this->assertSame(1, $data['translations']['count']);
        $this->assertTrue($data['translations']['update_available']);
        $this->assertCount(1, $data['translations']['items']);
        $this->assertSame('fr_FR', $data['translations']['items'][0]['language']);
    }
}
