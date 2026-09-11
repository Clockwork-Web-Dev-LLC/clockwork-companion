<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\TestCase;
use WP_User;

class WhiteLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = null;
    }

    public function testDefaultValuesWhenNoOptionsAreStored(): void
    {
        $this->assertFalse(WhiteLabel::isEnabled());
        $this->assertSame('Clockwork Companion', WhiteLabel::getPluginName());
        $this->assertSame('Clockwork Web Dev, LLC', WhiteLabel::getAuthorName());
        $this->assertSame('Clockwork', WhiteLabel::getMenuTitle());
        $this->assertFalse(WhiteLabel::isPluginRowHidden());
        $this->assertFalse(WhiteLabel::areHelpLinksHidden());
    }

    public function testActiveOverridesWhenEnabled(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'company_name' => 'Agency Prime',
            'company_url' => 'https://agencyprime.com',
            'support_email' => 'support@agencyprime.com',
            'support_url' => 'https://agencyprime.com/docs',
            'plugin_name' => 'Prime Guardian',
            'plugin_description' => 'Security & performance monitor.',
            'menu_title' => 'Prime Guardian',
            'brand_text' => 'Companion',
            'menu_icon' => 'dashicons-shield',
            'logo_url' => 'https://agencyprime.com/logo.svg',
            'hide_plugin_row' => true,
            'hide_help_links' => true,
            'footer_text' => 'Care provided by Agency Prime',
        ]);

        $this->assertTrue(WhiteLabel::isEnabled());
        $this->assertSame('Agency Prime', WhiteLabel::getAuthorName());
        $this->assertSame('Prime Guardian', WhiteLabel::getPluginName());
        $this->assertSame('Prime Guardian', WhiteLabel::getMenuTitle());
        $this->assertSame('Companion', WhiteLabel::getBrandText());
        $this->assertSame('dashicons-shield', WhiteLabel::getMenuIcon());
        $this->assertSame('https://agencyprime.com/logo.svg', WhiteLabel::getLogoUrl());
        $this->assertTrue(WhiteLabel::isPluginRowHidden());
        $this->assertTrue(WhiteLabel::areHelpLinksHidden());
        $this->assertSame('Care provided by Agency Prime', WhiteLabel::getFooterText());
        $this->assertSame('https://agencyprime.com/docs', WhiteLabel::getSupportUrl());
    }

    public function testSupportUrlFallsBackToMailtoWhenUrlIsEmpty(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'support_email' => 'ops@agencyprime.com',
            'support_url' => '',
        ]);

        $this->assertSame('mailto:ops@agencyprime.com', WhiteLabel::getSupportUrl());
    }

    public function testFilterAllPluginsModifiesCompanionMetadata(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'company_name' => 'Agency Alpha',
            'company_url' => 'https://agencyalpha.com',
            'plugin_name' => 'Alpha Sentinel',
            'plugin_description' => 'Custom Sentinel Suite',
        ]);

        $whiteLabel = new WhiteLabel();
        $plugins = [
            'clockwork-companion/clockwork-companion.php' => [
                'Name' => 'Clockwork Companion',
                'Title' => 'Clockwork Companion',
                'Description' => 'Original description',
                'Author' => 'Clockwork Web Dev, LLC',
            ],
            'akismet/akismet.php' => [
                'Name' => 'Akismet',
                'Author' => 'Automattic',
            ],
        ];

        // Simulate agency user viewing plugins
        $GLOBALS['wp_test_current_user'] = new WP_User('dev@clockworkwd.com');

        $filtered = $whiteLabel->filterAllPlugins($plugins);

        $this->assertSame('Alpha Sentinel', $filtered['clockwork-companion/clockwork-companion.php']['Name']);
        $this->assertSame('Custom Sentinel Suite', $filtered['clockwork-companion/clockwork-companion.php']['Description']);
        $this->assertSame('Agency Alpha', $filtered['clockwork-companion/clockwork-companion.php']['Author']);
        // Unrelated plugins are untouched
        $this->assertSame('Akismet', $filtered['akismet/akismet.php']['Name']);
    }

    public function testFilterAllPluginsHidesRowWhenConfiguredForClientAdmins(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'hide_plugin_row' => true,
            'support_email' => 'team@agencyalpha.com',
        ]);

        $whiteLabel = new WhiteLabel();
        $plugins = [
            'clockwork-companion/clockwork-companion.php' => ['Name' => 'Clockwork Companion'],
            'akismet/akismet.php' => ['Name' => 'Akismet'],
        ];

        // 1. Client admin (non-agency email)
        $GLOBALS['wp_test_current_user'] = new WP_User('client@acmecorp.com');
        $filteredClient = $whiteLabel->filterAllPlugins($plugins);
        $this->assertArrayNotHasKey('clockwork-companion/clockwork-companion.php', $filteredClient);
        $this->assertArrayHasKey('akismet/akismet.php', $filteredClient);

        // 2. Agency admin (matching operator domain)
        $GLOBALS['wp_test_current_user'] = new WP_User('support@agencyalpha.com');
        $filteredAgency = $whiteLabel->filterAllPlugins($plugins);
        $this->assertArrayHasKey('clockwork-companion/clockwork-companion.php', $filteredAgency);
    }

    public function testFilterPluginRowMetaStripsHelpLinksWhenHideHelpLinksIsTrue(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'company_name' => 'Agency Alpha',
            'company_url' => 'https://agencyalpha.com',
            'hide_help_links' => true,
        ]);

        $whiteLabel = new WhiteLabel();
        $meta = [
            '<a href="https://clockworkcontrol.com/docs">Documentation</a>',
            'By <a href="https://clockworkwd.com">Clockwork Web Dev, LLC</a>',
            '<a href="https://clockworkcontrol.com/support">Support</a>',
        ];

        $filtered = $whiteLabel->filterPluginRowMeta($meta, 'clockwork-companion/clockwork-companion.php');

        // Only author link should remain
        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('By <a href="https://agencyalpha.com">Agency Alpha</a>', $filtered[0]);
    }

    public function testFilterAdminFooterText(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'footer_text' => 'Custom Agency Footer Credit',
        ]);

        $whiteLabel = new WhiteLabel();
        $this->assertSame('Custom Agency Footer Credit', $whiteLabel->filterAdminFooterText('Default WP Footer'));

        // When disabled
        update_option(WhiteLabel::OPTION_KEY, ['enabled' => false, 'footer_text' => 'Custom Footer']);
        $this->assertSame('Default WP Footer', $whiteLabel->filterAdminFooterText('Default WP Footer'));
    }

    public function testBrandTextStaysIndependentOfMenuTitle(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'menu_title' => 'Clockwork',
            'brand_text' => 'Companion',
        ]);

        $settings = WhiteLabel::getSettings();

        $this->assertSame('Clockwork', $settings['menu_title']);
        $this->assertSame('Companion', $settings['brand_text']);
        $this->assertSame('Clockwork', WhiteLabel::getMenuTitle());
        $this->assertSame('Companion', WhiteLabel::getBrandText());
    }

    public function testInjectBrandingCssDoesNothingWhenDisabled(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => false,
            'primary_color' => '#0F172A',
        ]);

        $GLOBALS['hook_suffix'] = 'toplevel_page_clockwork';
        $whiteLabel = new WhiteLabel();

        ob_start();
        $whiteLabel->injectBrandingCss();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testInjectBrandingCssPreservesTwoToneHierarchyAndDerivesSoftColor(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'primary_color' => '#2D2062',
            'primary_dark_color' => '#2D2062',
            'accent_color' => '#7EFF83',
        ]);

        $GLOBALS['hook_suffix'] = 'toplevel_page_clockwork';
        $whiteLabel = new WhiteLabel();

        ob_start();
        $whiteLabel->injectBrandingCss();
        $output = ob_get_clean();

        $this->assertStringContainsString('--cwk-primary-dark: #2D2062;', $output);
        $this->assertStringContainsString('--cwk-accent: #7EFF83;', $output);
        $this->assertStringNotContainsString('--cwk-primary: #2D2062;', $output);
        $this->assertStringNotContainsString('--cwk-primary-soft: #D1C9F4;', $output);
    }

    public function testColorDerivationHelpers(): void
    {
        $dark = '#2D2062';
        $soft = WhiteLabel::deriveSoftColor($dark);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $soft);
        $this->assertNotSame('#D1C9F4', $soft);

        $medium = WhiteLabel::deriveMediumTone($dark);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $medium);
        $this->assertNotSame($dark, $medium);

        // Medium tone must be measurably brighter than the dark primary input
        $darkSum = hexdec(substr($dark, 1, 2)) + hexdec(substr($dark, 3, 2)) + hexdec(substr($dark, 5, 2));
        $mediumSum = hexdec(substr($medium, 1, 2)) + hexdec(substr($medium, 3, 2)) + hexdec(substr($medium, 5, 2));
        $this->assertGreaterThan($darkSum, $mediumSum);

        // Soft color must be a high-key pastel tint (RGB sum > 600)
        $softSum = hexdec(substr($soft, 1, 2)) + hexdec(substr($soft, 3, 2)) + hexdec(substr($soft, 5, 2));
        $this->assertGreaterThan(600, $softSum);
    }
}
