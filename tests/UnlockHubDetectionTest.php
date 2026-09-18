<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Admin\Pages\UnlockPage;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_User;

class UnlockHubDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS["wp_test_options"] = [];
        $GLOBALS["wp_test_current_user"] = null;
        $GLOBALS["wp_test_current_user_can"] = true;
        $GLOBALS["wp_test_home_url"] = "https://example.test";
        $GLOBALS["wp_test_actions"] = [];
        $GLOBALS["wp_test_submenu_pages"] = [];
    }

    public function testDefaultHubDomainIsClockworkWdCom(): void
    {
        $this->assertSame("clockworkwd.com", WhiteLabel::getUnlockHubDomain());
    }

    public function testUnlockHubDetectedWhenDomainMatchesAndUserIsAgencyEmployee(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://clockworkwd.com";
        $user = new WP_User("aaron@clockworkwd.com");

        $this->assertTrue(UnlockPage::isHub($user));
    }

    public function testUnlockHubHandlesWwwPrefixNormalization(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://www.clockworkwd.com";
        $user = new WP_User("tech@clockworkwd.com");

        $this->assertTrue(UnlockPage::isHub($user));
    }

    public function testUnlockHubRejectsWhenSiteDomainDoesNotMatchHubDomain(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://clientsite.com";
        $user = new WP_User("aaron@clockworkwd.com");

        // Even though Aaron is a Clockwork employee, clientsite.com is not the hub!
        $this->assertFalse(UnlockPage::isHub($user));
    }

    public function testUnlockHubRejectsNonAgencyUserOnHubDomain(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://clockworkwd.com";
        $user = new WP_User("client@someotherdomain.com");

        $this->assertFalse(UnlockPage::isHub($user));
    }

    public function testCustomUnlockHubDomainConfiguration(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            "enabled" => true,
            "unlock_hub_domain" => "myagencyportal.com",
        ]);

        $this->assertSame("myagencyportal.com", WhiteLabel::getUnlockHubDomain());

        $GLOBALS["wp_test_home_url"] = "https://myagencyportal.com";
        $agencyUser = new WP_User("admin@myagencyportal.com");
        $externalUser = new WP_User("someone@client.com");

        $this->assertTrue(UnlockPage::isHub($agencyUser));
        $this->assertFalse(UnlockPage::isHub($externalUser));
    }

    public function testSubdomainHubAcceptsParentDomainStaffEmail(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            "enabled" => true,
            "unlock_hub_domain" => "support.customagency.com",
        ]);

        $GLOBALS["wp_test_home_url"] = "https://support.customagency.com";

        $parentStaff = new WP_User("dev@customagency.com");
        $subdomainStaff = new WP_User("dev@support.customagency.com");
        $lookalikeUser = new WP_User("dev@notcustomagency.com");

        $this->assertTrue(UnlockPage::isHub($parentStaff));
        $this->assertTrue(UnlockPage::isHub($subdomainStaff));
        $this->assertFalse(UnlockPage::isHub($lookalikeUser));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnlockHubConstantOverrideIgnoresDomainAndUser(): void
    {
        define("CLOCKWORK_UNLOCK_HUB", true);
        $GLOBALS["wp_test_home_url"] = "https://unrelated-local.test";
        $this->assertTrue(UnlockPage::isHub(new WP_User("anyone@elsewhere.com")));
    }

    public function testAjaxAlwaysRegisteredEvenForNonHubUser(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://example.test";
        $GLOBALS["wp_test_current_user"] = new WP_User("client@external.com");

        (new Menu())->register();

        $this->assertNotEmpty($GLOBALS["wp_test_actions"]["wp_ajax_cw_unlock_site"] ?? null);
    }

    public function testDirectRenderAsNonHubUserDiesWith403(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://example.test";
        $GLOBALS["wp_test_current_user"] = new WP_User("client@external.com");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("wp_die [403]: Unauthorized");

        UnlockPage::render();
    }

    public function testDirectAjaxUnlockAsNonHubUserReturns403(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://example.test";
        $GLOBALS["wp_test_current_user"] = new WP_User("client@external.com");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("wp_send_json_error [403]: Insufficient permissions.");

        UnlockPage::ajaxUnlock();
    }

    public function testMenuRegistersAndRemovesUnlockSubmenuWhenNotHub(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://example.test";
        $GLOBALS["wp_test_current_user"] = new WP_User("client@external.com");
        $GLOBALS["wp_test_submenu_pages"] = [];

        (new Menu())->addMenu();

        // The submenu page was registered and then removed from submenu display
        $this->assertArrayNotHasKey(UnlockPage::SLUG, $GLOBALS["wp_test_submenu_pages"][Menu::SLUG] ?? []);
    }

    public function testUserWithoutEmailCannotAccessHub(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://clockworkwd.com";
        $user = new WP_User("");

        $this->assertFalse(UnlockPage::isHub($user));
    }

    public function testFallbackToCurrentUserWhenNoExplicitUserPassed(): void
    {
        $GLOBALS["wp_test_home_url"] = "https://clockworkwd.com";
        $GLOBALS["wp_test_current_user"] = new WP_User("dev@clockworkwd.com");

        $this->assertTrue(UnlockPage::isHub());

        $GLOBALS["wp_test_current_user"] = new WP_User("guest@other.com");
        $this->assertFalse(UnlockPage::isHub());
    }
}
