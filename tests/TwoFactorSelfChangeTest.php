<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Admin\Actions\TwoFactorActions;
use ClockworkCompanion\TwoFactor\Totp;
use ClockworkCompanion\TwoFactor\UserSettings;
use PHPUnit\Framework\TestCase;
use WP_User;
use wpdb;

class TwoFactorSelfChangeTest extends TestCase
{
    private const USER_ID = 51;

    /** @var wpdb&object{inserted: array<int, array<string, mixed>>} */
    private wpdb $db;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_users'] = [self::USER_ID => new WP_User('jane@example.com', self::USER_ID, 'jane', ['administrator'])];
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_did_actions'] = [];
        $this->db = new class extends wpdb {
            /** @var array<int, array<string, mixed>> */
            public array $inserted = [];

            public function insert(string $table, array $data, array|string $format = []): int|false
            {
                if (str_ends_with($table, 'action_log')) {
                    $this->inserted[] = $data;
                }

                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->db;

        $this->secret = Totp::generateSecret();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        parent::tearDown();
    }

    private function enroll(): void
    {
        update_user_meta(self::USER_ID, UserSettings::META_SECRET, $this->secret);
        update_user_meta(self::USER_ID, UserSettings::META_ENABLED, '1');
    }

    public function testChangingAnActiveFactorNeedsACurrentCode(): void
    {
        $this->enroll();

        foreach (TwoFactorActions::OPS_NEEDING_CURRENT_CODE as $op) {
            $this->assertNotNull(TwoFactorActions::refusalForActiveFactorChange(self::USER_ID, $op, ''), $op);
        }

        $this->assertSame(['2fa_change_refused', '2fa_change_refused', '2fa_change_refused'], array_column($this->db->inserted, 'action_type'));
        $this->assertSame(0, $this->db->inserted[0]['ok']);
    }

    public function testCurrentCodeOrBackupCodeAllowsTheChange(): void
    {
        $this->enroll();
        $code = Totp::code((string) Totp::base32Decode($this->secret), time());
        $this->assertNull(TwoFactorActions::refusalForActiveFactorChange(self::USER_ID, 'disable', $code));

        $backup = UserSettings::generateBackupCodes(self::USER_ID)[0];
        $this->assertNull(TwoFactorActions::refusalForActiveFactorChange(self::USER_ID, 'regenerate', $backup));
    }

    public function testNothingToProveWhenTwoFactorIsOff(): void
    {
        $this->assertNull(TwoFactorActions::refusalForActiveFactorChange(self::USER_ID, 'confirm', ''));
    }

    public function testFlashMessagesSurviveAmpersandsAndHashes(): void
    {
        $url = TwoFactorActions::redirectUrl(['flash' => 'Set Tom & Jerry #2’s grace period']);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        // rawurldecode() is a no-op on the plain text and undoes the extra
        // encoding layer of a test stub that encodes values itself.
        $this->assertSame('Set Tom & Jerry #2’s grace period', rawurldecode($query['flash']));
        $this->assertStringNotContainsString('#', $url);
    }
}
