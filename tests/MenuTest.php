<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\TestCase;
use WP_User;

class MenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = null;
    }

    public function testCurrentUserIsAgencyMatchesDefaultDomains(): void
    {
        $agencyUser1 = new WP_User('aaron@clockworkwp.com');
        $agencyUser2 = new WP_User('team@clockworkwd.com');
        $clientUser = new WP_User('client@clientdomain.com');

        $this->assertTrue(Menu::currentUserIsAgency($agencyUser1));
        $this->assertTrue(Menu::currentUserIsAgency($agencyUser2));
        $this->assertFalse(Menu::currentUserIsAgency($clientUser));
    }

    public function testCurrentUserIsAgencyIncludesWhiteLabelSupportEmailDomain(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'support_email' => 'tech@superagency.io',
        ]);

        $customAgencyUser = new WP_User('sarah@superagency.io');
        $clientUser = new WP_User('owner@someshop.com');

        $this->assertTrue(Menu::currentUserIsAgency($customAgencyUser));
        $this->assertFalse(Menu::currentUserIsAgency($clientUser));
    }
}
