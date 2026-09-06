<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_User;

class MenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = null;
        $GLOBALS['wp_test_filters'] = [];
    }

    /**
     * The out-of-the-box case: nothing configured, so nothing is hidden. A
     * fresh install must not hide its own menu from the administrator who
     * just installed it.
     */
    public function testNoConfiguredDomainsMeansEveryAdministratorSeesTheMenu(): void
    {
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('admin@clientdomain.com')));
    }

    public function testConfiguredDomainsGateTheMenu(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'agency_email_domains' => '@agencyprime.com',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agencyprime.com')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
    }

    /**
     * Gating is an access-control decision, so it must apply whether or not
     * the cosmetic white-label flag happens to be switched on.
     */
    public function testGatingAppliesEvenWhenWhiteLabelIsDisabled(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => false,
            'agency_email_domains' => 'agencyprime.com',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agencyprime.com')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
    }

    /**
     * An operator who set a support address should never be able to lock
     * themselves out by forgetting to also list that domain.
     */
    public function testSupportEmailDomainIsAlwaysTreatedAsAgency(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'agency_email_domains' => '@agencyprime.com',
            'support_email' => 'tech@superagency.io',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agencyprime.com')));
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('sarah@superagency.io')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
    }

    #[DataProvider('domainFormatProvider')]
    public function testDomainListAcceptsCommonFormats(string $configured): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => $configured]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agencyprime.com')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function domainFormatProvider(): array
    {
        return [
            'bare domain' => ['agencyprime.com'],
            'leading at' => ['@agencyprime.com'],
            'full address' => ['someone@agencyprime.com'],
            'uppercase' => ['@AgencyPrime.COM'],
            'comma separated' => ['@other.test, @agencyprime.com'],
            'newline separated' => ["@other.test\n@agencyprime.com"],
            'padded with spaces' => ['  @agencyprime.com  '],
        ];
    }

    public function testMatchIsCaseInsensitiveOnTheUserAddress(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agencyprime.com']);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('OPS@AgencyPrime.com')));
    }

    /**
     * A configured domain must not match a lookalike registrable domain —
     * "notagencyprime.com" ends with "agencyprime.com" as a bare substring,
     * so the "@" in the stored form is what keeps them apart.
     */
    public function testLookalikeDomainDoesNotMatch(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agencyprime.com']);

        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('ops@notagencyprime.com')));
    }

    public function testFilterCanOverrideConfiguredDomains(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agencyprime.com']);

        add_filter('clockwork_companion_agency_email_domains', fn () => ['@vanguardweb.dev']);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('help@vanguardweb.dev')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('ops@agencyprime.com')));
    }

    public function testUnparseableEntriesAreIgnoredRatherThanGatingOnNothing(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@, ,,  ']);

        // Nothing parseable survived, so this is the "unconfigured" case.
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('owner@someshop.com')));
    }

    public function testUserWithoutAnEmailIsNotAgencyWhenGatingIsOn(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agencyprime.com']);

        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('')));
    }
}
