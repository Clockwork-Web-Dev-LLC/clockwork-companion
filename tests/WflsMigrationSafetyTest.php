<?php

namespace ClockworkCompanion\Tests;

use ClockworkCompanion\Rest\TwoFactorMigrateRoute;
use ClockworkCompanion\TwoFactor\UserSettings;
use ClockworkCompanion\TwoFactor\WflsMigrator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_User;
use wpdb;

/**
 * Separate processes: WflsMigrator caches "does the WFLS table exist" in a
 * static for the life of the process.
 */
class WflsMigrationSafetyTest extends TestCase
{
    private const USER_ID = 71;

    /** @var wpdb&object{deleted: array<int, mixed>} */
    private wpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_users'] = [self::USER_ID => new WP_User('jane@example.com', self::USER_ID, 'jane', ['administrator'])];
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_update_user_meta_fails'] = [];
        $this->db = new class extends wpdb {
            /** @var array<int, mixed> */
            public array $deleted = [];

            public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
            {
                if (str_starts_with((string) $query, 'SHOW TABLES LIKE')) {
                    return 'wp_wfls_2fa_secrets';
                }
                if (str_contains((string) $query, 'SELECT secret FROM')) {
                    return '12345678901234567890';
                }

                return null;
            }

            public function delete(string $table, array $where, array|string $where_format = []): int|false
            {
                $this->deleted[] = [$table, $where];

                return 1;
            }

            public function insert(string $table, array $data, array|string $format = []): int|false
            {
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWflsRowIsKeptWhenTheNewSecretWasNotStored(): void
    {
        $GLOBALS['wp_test_update_user_meta_fails'][UserSettings::META_SECRET] = true;

        $this->assertFalse(WflsMigrator::migrate(self::USER_ID));
        $this->assertSame([], $this->db->deleted);
        $this->assertFalse(UserSettings::isEnabled(self::USER_ID));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInteractiveMigrationReturnsCodesAndRemovesTheWflsRow(): void
    {
        $codes = WflsMigrator::migrate(self::USER_ID);

        $this->assertCount(UserSettings::BACKUP_CODE_COUNT, $codes);
        $this->assertTrue(UserSettings::isEnabled(self::USER_ID));
        $this->assertCount(1, $this->db->deleted);
        $this->assertFalse(UserSettings::backupCodesNeeded(self::USER_ID));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoteMigrationKeepsNoUnseenCodesAndFlagsRegeneration(): void
    {
        $data = (new TwoFactorMigrateRoute())->handle(new WP_REST_Request('POST', '/clockwork/v1/two-factor/migrate', ['user_id' => self::USER_ID]))->get_data();

        $this->assertTrue($data['migrated']);
        $this->assertTrue($data['backup_codes_need_regenerating']);
        $this->assertTrue(UserSettings::isEnabled(self::USER_ID));
        $this->assertSame(0, UserSettings::backupCodesRemaining(self::USER_ID));
        $this->assertTrue(UserSettings::backupCodesNeeded(self::USER_ID));

        UserSettings::generateBackupCodes(self::USER_ID);
        $this->assertFalse(UserSettings::backupCodesNeeded(self::USER_ID));
    }
}
