<?php

namespace ClockworkCompanion\TwoFactor;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;

/**
 * Migrates per-user 2FA setups from Wordfence Login Security (the
 * standalone plugin Wordfence is discontinuing) into Companion's 2FA.
 *
 * Schema facts verified against a live WFLS 1.1.16 install
 * (2026-07-22) — NOT the folklore floating around support forums:
 *
 *   - {prefix}wfls_2fa_secrets.secret is a RAW BINARY 20-byte tinyblob
 *     (not hex-encoded). Conversion to our format is a straight
 *     binary → base32 encode; the user's authenticator app keeps
 *     producing valid codes because the underlying key is identical.
 *   - "2FA enabled" is simply "a row exists in wfls_2fa_secrets for
 *     this user" — there is no wfls-2fa-active user meta.
 *   - Detection: the `wordfence_ls_version` wp_option persists across
 *     deactivation, as do the wfls_* tables.
 *   - recovery is 40 raw bytes (5 × 8-byte codes, unhashed). We don't
 *     migrate them: fresh Companion backup codes are generated instead,
 *     shown once at migration time, hashed at rest.
 *
 * Migration is per-user and user-initiated (each admin clicks the
 * banner on their own account) — never fleet-batch, so one admin
 * migrating can't surprise another.
 */
class WflsMigrator
{
    public const VERSION_OPTION = 'wordfence_ls_version';

    public const PLUGIN_FILE = 'wordfence-login-security/wordfence-login-security.php';

    /**
     * True when WFLS is or was installed here: version option survives
     * deactivation, and the secrets table survives even plugin deletion.
     */
    public static function isWflsPresent(): bool
    {
        return get_option(self::VERSION_OPTION, false) !== false
            || self::tableExists();
    }

    /**
     * True when the plugin's data is still in the DB but the plugin
     * itself is no longer active — the "migrate now or lose it" state:
     * WFLS's login gate is already gone, so 2FA is silently OFF for
     * users who think they still have it.
     */
    public static function wflsInactiveButDataPresent(): bool
    {
        return self::isWflsPresent() && ! self::isWflsActive();
    }

    public static function isWflsActive(): bool
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        return is_plugin_active(self::PLUGIN_FILE);
    }

    /**
     * Does this user have a WFLS TOTP secret worth migrating?
     * Skips users who already enrolled in Companion 2FA — migration
     * must never clobber a working newer setup.
     */
    public static function hasMigratable(int $userId): bool
    {
        if (UserSettings::isEnabled($userId)) {
            return false;
        }

        return self::rawSecret($userId) !== null;
    }

    /**
     * Copy the user's WFLS secret into Companion 2FA and return the
     * fresh backup codes for one-time display. Returns false when there
     * is nothing to migrate (or the secret row is malformed).
     *
     * The WFLS secret row is deleted after migration. Leaving it in place
     * would cause WFLS's authenticate hook (priority 25) to intercept the
     * login and produce an error before Companion's gate (PHP_INT_MAX) can
     * show the 2FA challenge. The underlying binary key is identical in both
     * systems, so the user's authenticator app entry keeps working regardless.
     *
     * @return array<int, string>|false
     */
    public static function migrate(int $userId)
    {
        global $wpdb;

        if (UserSettings::isEnabled($userId)) {
            return false;
        }

        $binary = self::rawSecret($userId);
        if ($binary === null) {
            return false;
        }

        $codes = UserSettings::activateWithSecret($userId, Totp::base32Encode($binary));

        // Remove the WFLS row so its authenticate hook no longer challenges
        // this user — the secret is now in Companion meta.
        $wpdb->delete(self::tableName(), ['user_id' => $userId], ['%d']);

        $user = get_userdata($userId);
        ActionLogRepository::insert([
            'action_type' => '2fa_wfls_migration',
            'target' => $user ? $user->user_login : (string) $userId,
            'summary' => 'Migrated two-factor setup from Wordfence Login Security — same authenticator app entry keeps working.',
            'ok' => true,
            'actor' => 'manual',
        ]);

        return $codes;
    }

    /**
     * How many EXISTING users still depend on WFLS for their 2FA — i.e.
     * have a WFLS secret but no Companion enrollment. All roles, not just
     * admins/editors: deactivating WFLS kills the gate for every one of
     * them, so every one of them blocks removal. Orphan rows for deleted
     * users don't count.
     */
    public static function remainingUnmigratedCount(): int
    {
        global $wpdb;

        if (! self::tableExists()) {
            return 0;
        }

        $userIds = $wpdb->get_col(
            'SELECT DISTINCT s.user_id FROM '.self::tableName().' s'
            ." INNER JOIN {$wpdb->users} u ON u.ID = s.user_id"
            ." WHERE s.mode = 'authenticator'"
        );

        $count = 0;
        foreach ($userIds as $uid) {
            if (! UserSettings::isEnabled((int) $uid)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * True when the WFLS plugin is installed (files on disk) and nobody's
     * 2FA depends on it anymore — the moment to suggest removing it.
     */
    public static function readyToRemove(): bool
    {
        return self::pluginInstalled() && self::remainingUnmigratedCount() === 0;
    }

    public static function pluginInstalled(): bool
    {
        return is_file(WP_PLUGIN_DIR.'/'.self::PLUGIN_FILE);
    }

    /**
     * Deactivate and delete the WFLS plugin. Guarded: refuses while any
     * existing user still depends on WFLS for 2FA.
     *
     * Deactivation always succeeds; deletion (WP_Filesystem) can fail on
     * exotic hosts, in which case we report partial success — a
     * deactivated WFLS is already harmless, the files are just clutter.
     * Note delete_plugins() runs the plugin's uninstall routine, which may
     * drop the wfls_* tables — fine, since nobody depends on them by the
     * time this runs.
     *
     * @return array{ok: bool, message: string}
     */
    public static function removeWfls(): array
    {
        if (! self::pluginInstalled()) {
            return ['ok' => false, 'message' => 'Wordfence Login Security is not installed.'];
        }
        if (self::remainingUnmigratedCount() > 0) {
            return ['ok' => false, 'message' => 'Refusing to remove — users still depend on Wordfence Login Security for two-factor.'];
        }

        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        deactivate_plugins(self::PLUGIN_FILE, true);

        $deleted = false;
        try {
            if (! function_exists('delete_plugins')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
            }
            require_once ABSPATH.'wp-admin/includes/file.php';
            $result = delete_plugins([self::PLUGIN_FILE]);
            $deleted = $result === true;
        } catch (\Throwable $e) {
            $deleted = false;
        }

        $message = $deleted
            ? 'Wordfence Login Security deactivated and removed.'
            : 'Wordfence Login Security deactivated. The plugin files could not be deleted automatically — remove them from the Plugins screen.';

        ActionLogRepository::insert([
            'action_type' => '2fa_wfls_removed',
            'target' => self::PLUGIN_FILE,
            'summary' => $message,
            'ok' => true,
            'actor' => 'manual',
        ]);

        return ['ok' => true, 'message' => $message];
    }

    /**
     * @return string|null raw 20-byte binary secret, null when absent
     */
    private static function rawSecret(int $userId): ?string
    {
        global $wpdb;

        if (! self::tableExists()) {
            return null;
        }

        $secret = $wpdb->get_var($wpdb->prepare(
            'SELECT secret FROM '.self::tableName()." WHERE user_id = %d AND mode = 'authenticator' ORDER BY id DESC LIMIT 1",
            $userId
        ));

        // 20 bytes is what WFLS writes; anything else means a schema we
        // haven't seen — refuse rather than migrate a broken secret.
        if (! is_string($secret) || strlen($secret) !== 20) {
            return null;
        }

        return $secret;
    }

    private static function tableExists(): bool
    {
        global $wpdb;

        static $exists = null;
        if ($exists === null) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::tableName())) !== null;
        }

        return $exists;
    }

    private static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix.'wfls_2fa_secrets';
    }
}
