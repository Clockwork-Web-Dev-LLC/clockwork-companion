<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Gatekeeper\ClientIp as GatekeeperClientIp;
use ClockworkCompanion\Gatekeeper\Settings as GatekeeperSettings;
use ClockworkCompanion\Gatekeeper\Store as GatekeeperStore;

/**
 * Clockwork → Gatekeeper admin page: login-lockout status, active lockouts
 * with unlock buttons, and the policy.
 *
 * Agency users only (Menu::currentUserIsAgency()), on top of manage_options:
 * the page shows other visitors' IP addresses and can lift lockouts. It is
 * also where the one setting Clockwork does not push, Trusted Proxies, is
 * set — the other fields are overwritten by the next push from Clockwork,
 * which the page says next to the form.
 */
class GatekeeperPage
{
    public const SLUG = 'clockwork-gatekeeper';

    public static function canAccess(): bool
    {
        return current_user_can(Menu::CAPABILITY) && Menu::currentUserIsAgency();
    }

    public static function render(): void
    {
        if (! self::canAccess()) {
            wp_die('Unauthorized', 403);
        }

        Layout::render('gatekeeper', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $settings = GatekeeperSettings::get();
        $lockouts = GatekeeperStore::activeLockouts();
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $unlocked = isset($_GET['unlocked']) ? sanitize_text_field((string) wp_unslash($_GET['unlocked'])) : '';
        $unlockedAll = isset($_GET['unlocked_all']) && $_GET['unlocked_all'] === '1';

        $enabled = GatekeeperSettings::enabled();
        $forcedOff = defined('CLOCKWORK_GATEKEEPER_DISABLE') && constant('CLOCKWORK_GATEKEEPER_DISABLE');
        $threshold = (int) $settings['threshold'];
        $windowMinutes = (int) round(((int) $settings['window_seconds']) / 60);
        $lockoutMinutes = (int) round(((int) $settings['lockout_seconds']) / 60);
        $consecutiveExtended = (int) $settings['consecutive_lockouts_for_extended'];
        $extendedHours = (int) round(((int) $settings['extended_lockout_seconds']) / 3600);
        $ignoreIps = implode("\n", (array) $settings['ignore_ips']);
        $ignoreCidrs = implode("\n", (array) $settings['ignore_cidrs']);
        $trustedProxies = implode("\n", (array) $settings['trusted_proxies']);

        // The admin's own request travels the same path as a visitor's, so
        // the address Gatekeeper resolves for it shows whether lockouts can
        // work at all.
        $client = GatekeeperClientIp::resolve();
        $clientIsPrivatePeer = $client['source'] === GatekeeperClientIp::SOURCE_PEER && GatekeeperStore::isPrivate($client['ip']);
        $clientIsUntrustedCdn = $client['source'] === GatekeeperClientIp::SOURCE_PEER && GatekeeperClientIp::isCloudflare($client['peer']);

        Layout::pageHeader(
            'Gatekeeper',
            'Login lockouts: an address that fails to sign in too often is blocked for a while.'
        );
        ?>

        <?php if ($clientIsPrivatePeer) : ?>
            <div class="notice notice-warning" style="margin:0 0 20px;">
                <p><strong>Gatekeeper is not protecting this site</strong></p>
                <p><?php echo esc_html(sprintf(
                    'This request reached WordPress from %s, a private address. Private addresses are never throttled, so if visitors arrive through the same proxy or load balancer, no one can be locked out. Add the proxy under Trusted Proxies (or have the web server pass the real client address in REMOTE_ADDR).',
                    $client['ip']
                )); ?></p>
            </div>
        <?php elseif ($clientIsUntrustedCdn) : ?>
            <div class="notice notice-warning" style="margin:0 0 20px;">
                <p><strong>Requests arrive from Cloudflare</strong></p>
                <p><?php echo esc_html(sprintf(
                    'This request came from the Cloudflare address %s. Without "cloudflare" under Trusted Proxies, every visitor shares Cloudflare\'s addresses and a few failed logins by anyone lock out everyone using the same edge.',
                    $client['peer']
                )); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($saved) : ?>
            <div class="notice notice-success" style="margin:0 0 20px;"><p>Gatekeeper settings saved.</p></div>
        <?php endif; ?>
        <?php if ($unlocked !== '') : ?>
            <div class="notice notice-success" style="margin:0 0 20px;"><p><?php echo esc_html(sprintf('%s has been unlocked.', $unlocked)); ?></p></div>
        <?php endif; ?>
        <?php if ($unlockedAll) : ?>
            <div class="notice notice-success" style="margin:0 0 20px;"><p>All lockouts have been cleared.</p></div>
        <?php endif; ?>

        <div class="clockwork-card" style="max-width:960px;margin-bottom:20px;">
            <div class="clockwork-card__head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">Status</h2>
                <?php if ($enabled) : ?>
                    <span class="cwk-gatekeeper-status" style="padding:3px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:600;">● Enabled</span>
                <?php else : ?>
                    <span class="cwk-gatekeeper-status" style="padding:3px 10px;border-radius:999px;background:#f3f4f6;color:#4b5563;font-size:12px;font-weight:600;">Disabled</span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <?php if ($forcedOff) : ?>
                    <p>Switched off on this server by the CLOCKWORK_GATEKEEPER_DISABLE constant in wp-config.php.</p>
                <?php elseif (! $enabled) : ?>
                    <p>Off until Clockwork turns it on for this site. Failed logins are not counted.</p>
                <?php endif; ?>
                <p style="margin:0;"><?php echo esc_html(sprintf(
                    '%1$d failed attempts within %2$d minutes lock an address for %3$d minutes; after %4$d lockouts in a row, for %5$d hours.',
                    $threshold,
                    $windowMinutes,
                    $lockoutMinutes,
                    $consecutiveExtended,
                    $extendedHours
                )); ?></p>
            </div>
        </div>

        <div class="clockwork-card" style="max-width:960px;margin-bottom:20px;">
            <div class="clockwork-card__head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">Active lockouts (<?php echo (int) count($lockouts); ?>)</h2>
                <?php if ($lockouts !== []) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Clear all active lockouts?');">
                        <?php wp_nonce_field('clockwork_gatekeeper_unlock_all'); ?>
                        <input type="hidden" name="action" value="clockwork_gatekeeper_unlock_all" />
                        <button type="submit" class="button">Unlock all</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <?php if ($lockouts === []) : ?>
                    <p style="margin:0;">No address is locked out.</p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>IP address</th><th>Unlocks (UTC)</th><th style="width:120px;"></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lockouts as $item) : ?>
                                <?php
                                $ip = (string) $item['ip'];
                                $unlockTs = $item['unlock_at'] !== null ? strtotime((string) $item['unlock_at']) : false;
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($ip); ?></code></td>
                                    <td>
                                        <?php if ($unlockTs) : ?>
                                            <?php echo esc_html(gmdate('Y-m-d H:i:s', $unlockTs)); ?>
                                            <span style="color:#6b7280;">(in <?php echo (int) max(1, (int) round(($unlockTs - time()) / 60)); ?> min)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('clockwork_gatekeeper_unlock_' . $ip); ?>
                                            <input type="hidden" name="action" value="clockwork_gatekeeper_unlock_ip" />
                                            <input type="hidden" name="ip" value="<?php echo esc_attr($ip); ?>" />
                                            <button type="submit" class="button button-small">Unlock</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="clockwork-card" style="max-width:960px;">
            <?php wp_nonce_field('clockwork_gatekeeper_save'); ?>
            <input type="hidden" name="action" value="clockwork_gatekeeper_save_settings" />
            <div class="clockwork-card__head"><h2 style="margin:0;">Settings</h2></div>
            <div class="clockwork-card__body">
                <p style="color:#6b7280;">
                    Clockwork pushes this site's policy and replaces everything here except Trusted Proxies with each push. Change the policy in Clockwork to make it stick; use this form for a quick local fix.
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Protection</th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked((bool) $settings['enabled']); ?> /> Enable Gatekeeper</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-threshold">Failed attempts before lockout</label></th>
                        <td><input id="cwk-gk-threshold" type="number" min="1" max="50" name="threshold" value="<?php echo esc_attr((string) $threshold); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-window">Counting window (minutes)</label></th>
                        <td><input id="cwk-gk-window" type="number" min="1" max="1440" name="window_minutes" value="<?php echo esc_attr((string) $windowMinutes); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-lockout">Lockout (minutes)</label></th>
                        <td><input id="cwk-gk-lockout" type="number" min="1" max="10080" name="lockout_minutes" value="<?php echo esc_attr((string) $lockoutMinutes); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-consecutive">Lockouts in a row before the long lockout</label></th>
                        <td><input id="cwk-gk-consecutive" type="number" min="1" max="20" name="consecutive_lockouts_for_extended" value="<?php echo esc_attr((string) $consecutiveExtended); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-extended">Long lockout (hours)</label></th>
                        <td><input id="cwk-gk-extended" type="number" min="1" max="720" name="extended_lockout_hours" value="<?php echo esc_attr((string) $extendedHours); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-ignore-ips">Never lock out (IPs)</label></th>
                        <td><textarea id="cwk-gk-ignore-ips" name="ignore_ips" rows="3" class="large-text code"><?php echo esc_textarea($ignoreIps); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-ignore-cidrs">Never lock out (CIDRs)</label></th>
                        <td><textarea id="cwk-gk-ignore-cidrs" name="ignore_cidrs" rows="3" class="large-text code"><?php echo esc_textarea($ignoreCidrs); ?></textarea>
                            <p class="description">One per line. A connection from a private network address (127.0.0.1, 10.0.0.0/8, 192.168.0.0/16, …) is never locked out either.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cwk-gk-proxies">Trusted Proxies</label></th>
                        <td><textarea id="cwk-gk-proxies" name="trusted_proxies" rows="3" class="large-text code"><?php echo esc_textarea($trustedProxies); ?></textarea>
                            <p class="description">Connections from these addresses may name the real visitor in the CF-Connecting-IP or X-Forwarded-For header; from anywhere else those headers are ignored. One IP or CIDR per line, or "cloudflare" for Cloudflare's published ranges and "private" for loopback and private networks. When left empty, Cloudflare's addresses may still name the visitor in CF-Connecting-IP (and nothing else is trusted); enter "none" to trust no connection at all.</p>
                            <p class="description"><?php echo esc_html(sprintf('This request: client %1$s, connection from %2$s.', $client['ip'], $client['peer'])); ?></p></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">Save Gatekeeper settings</button></p>
            </div>
        </form>
        <?php
    }

    public static function handleSave(): void
    {
        if (! self::canAccess()) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_gatekeeper_save');

        // WordPress adds slashes to $_POST; without unslashing, an apostrophe
        // would gain a backslash on every save.
        $post = wp_unslash($_POST);

        GatekeeperSettings::update([
            'enabled' => ! empty($post['enabled']),
            'threshold' => (int) ($post['threshold'] ?? 4),
            'window_seconds' => max(1, min(1440, (int) ($post['window_minutes'] ?? 20))) * 60,
            'lockout_seconds' => max(1, min(10080, (int) ($post['lockout_minutes'] ?? 20))) * 60,
            'consecutive_lockouts_for_extended' => (int) ($post['consecutive_lockouts_for_extended'] ?? 4),
            'extended_lockout_seconds' => max(1, min(720, (int) ($post['extended_lockout_hours'] ?? 24))) * 3600,
            'ignore_ips' => explode("\n", (string) ($post['ignore_ips'] ?? '')),
            'ignore_cidrs' => explode("\n", (string) ($post['ignore_cidrs'] ?? '')),
            'trusted_proxies' => (string) ($post['trusted_proxies'] ?? ''),
        ]);

        self::redirectAndExit(['saved' => '1']);
    }

    public static function handleUnlockIp(): void
    {
        if (! self::canAccess()) {
            wp_die('Unauthorized', 403);
        }

        $ip = trim(sanitize_text_field((string) wp_unslash($_POST['ip'] ?? '')));
        check_admin_referer('clockwork_gatekeeper_unlock_' . $ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            wp_die('Invalid IP address.', 400);
        }

        GatekeeperStore::unlockIp($ip);

        self::redirectAndExit(['unlocked' => rawurlencode($ip)]);
    }

    public static function handleUnlockAll(): void
    {
        if (! self::canAccess()) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_gatekeeper_unlock_all');

        GatekeeperStore::unlockAll();

        self::redirectAndExit(['unlocked_all' => '1']);
    }

    /**
     * @param  array<string, string>  $args
     */
    private static function redirectAndExit(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php')));
        // admin-post.php never runs under the CLI SAPI; the test suite does,
        // and needs the handler to return.
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }
}
