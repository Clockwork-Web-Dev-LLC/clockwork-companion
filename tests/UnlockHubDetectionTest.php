<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Pages\UnlockPage;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
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
