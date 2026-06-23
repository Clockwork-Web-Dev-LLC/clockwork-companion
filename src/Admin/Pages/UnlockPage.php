<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\Admin\Menu;

/**
 * Clockwork → Unlock admin page.
 *
 * Central tool for agency managers to clear LLAR lockouts on any managed site
 * without needing WP access to that site. Sites are pre-configured with their
 * companion secret; the unlock request is HMAC-signed and fired server-side.
 *
 * Access: manage_options + agency email domain (enforced by Menu::maybeHideMenu).
 * Stored data: wp_options key `cw_unlock_sites` — array of {domain, secret} pairs.
 */
class UnlockPage
{
    public const SLUG = 'clockwork-unlock';

    private const OPTION_KEY = 'cw_unlock_sites';

    private const NONCE_ACTION = 'cw_unlock_manage';

    public const AJAX_ACTION = 'cw_unlock_site';

    public static function render(): void
    {
        Layout::render('unlock', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $importResult = null;

        if (isset($_POST['cw_unlock_action']) && check_admin_referer(self::NONCE_ACTION)) {
            $act = sanitize_text_field((string) $_POST['cw_unlock_action']);
            if ($act === 'add') {
                self::handleAdd();
            } elseif ($act === 'remove') {
                self::handleRemove();
            } elseif ($act === 'import') {
                $importResult = self::handleImport();
            }
        }

        $sites = self::getSites();

        Layout::pageHeader(
            'Unlock Lockouts',
            'Clear LLAR lockouts on any managed site instantly — no WP login needed on the target site.'
        );

        self::renderUnlockCard($sites);
        self::renderSitesCard($sites, $importResult);
        self::renderInlineStyles();
        self::renderScript();
    }

    public static function registerAjax(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxUnlock']);
    }

    public static function ajaxUnlock(): void
    {
        check_ajax_referer(self::NONCE_ACTION, '_nonce');

        if (! current_user_can(Menu::CAPABILITY)) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $domain = isset($_POST['domain']) ? sanitize_text_field((string) $_POST['domain']) : '';
        if ($domain === '') {
            wp_send_json_error(['message' => 'No domain provided.'], 400);
        }

        $secret = '';
        foreach (self::getSites() as $site) {
            if ($site['domain'] === $domain) {
                $secret = $site['secret'];
                break;
            }
        }

        if ($secret === '') {
            wp_send_json_error(['message' => "No secret configured for {$domain}."], 400);
        }

        $ts = time();
        $payload = "DELETE\n/wp-json/clockwork/v1/lockouts\n{$ts}\n";
        $sig = hash_hmac('sha256', $payload, $secret);

        $response = wp_remote_request("https://{$domain}/wp-json/clockwork/v1/lockouts", [
            'method' => 'DELETE',
            'headers' => [
                'X-Clockwork-Signature' => $sig,
                'X-Clockwork-Timestamp' => (string) $ts,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Request failed: ' . $response->get_error_message()], 502);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['ok'])) {
            $errMsg = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : "HTTP {$code}";
            wp_send_json_error(['message' => "Site responded: {$errMsg}"], 502);
        }

        wp_send_json_success([
            'domain'  => $domain,
            'cleared' => (int) ($body['cleared'] ?? 0),
        ]);
    }

    private static function handleAdd(): void
    {
        $domain = isset($_POST['domain']) ? sanitize_text_field((string) $_POST['domain']) : '';
        $secret = isset($_POST['secret']) ? sanitize_text_field((string) $_POST['secret']) : '';

        // Strip protocol/trailing slash so admins can paste a full URL carelessly.
        $domain = preg_replace('#^https?://#i', '', rtrim($domain, '/'));

        if ($domain === '' || $secret === '') {
            return;
        }

        $sites = self::getSites();
        foreach ($sites as &$site) {
            if ($site['domain'] === $domain) {
                $site['secret'] = $secret;
                update_option(self::OPTION_KEY, $sites);

                return;
            }
        }
        unset($site);

        $sites[] = ['domain' => $domain, 'secret' => $secret];
        update_option(self::OPTION_KEY, $sites);
    }

    /**
     * @return array{added: int, updated: int, skipped: int}
     */
    private static function handleImport(): array
    {
        $raw = isset($_POST['import_data'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['import_data']))
            : '';

        $added   = 0;
        $updated = 0;
        $skipped = 0;

        $sites   = self::getSites();
        $siteMap = [];
        foreach ($sites as $i => $site) {
            $siteMap[$site['domain']] = $i;
        }

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue;
            }

            $cols = explode('|', $line);
            if (count($cols) < 4) {
                continue;
            }

            $domain = trim($cols[1]);
            $secret = trim($cols[3], " \t`");

            // Skip header and separator rows.
            if ($domain === '' || preg_match('/^[-: ]+$/', $domain) || strtolower($domain) === 'site') {
                continue;
            }

            $domain = preg_replace('#^https?://#i', '', rtrim($domain, '/'));

            if ($domain === '' || ! preg_match('/^[a-f0-9]{64}$/', $secret)) {
                $skipped++;
                continue;
            }

            if (isset($siteMap[$domain])) {
                if ($sites[$siteMap[$domain]]['secret'] !== $secret) {
                    $sites[$siteMap[$domain]]['secret'] = $secret;
                    $updated++;
                }
            } else {
                $siteMap[$domain] = count($sites);
                $sites[]          = ['domain' => $domain, 'secret' => $secret];
                $added++;
            }
        }

        update_option(self::OPTION_KEY, array_values($sites));

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    private static function handleRemove(): void
    {
        $domain = isset($_POST['domain']) ? sanitize_text_field((string) $_POST['domain']) : '';
        if ($domain === '') {
            return;
        }

        $filtered = array_values(array_filter(self::getSites(), fn ($s) => $s['domain'] !== $domain));
        update_option(self::OPTION_KEY, $filtered);
    }

    /**
     * @return array<int, array{domain: string, secret: string}>
     */
    private static function getSites(): array
    {
        $raw = get_option(self::OPTION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<int, array{domain: string, secret: string}>  $sites
     */
    private static function renderUnlockCard(array $sites): void
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="clockwork-card cw-unlock-card">
            <div class="clockwork-card__head">
                <h2>Unlock site</h2>
            </div>
            <div class="clockwork-card__body">
                <?php if (empty($sites)) : ?>
                    <div class="clockwork-notice clockwork-notice--muted">
                        No sites configured yet — add one in the section below.
                    </div>
                <?php else : ?>
                    <p style="margin: 0 0 14px; color: #4b5563; font-size: 13px;">
                        Enter a domain to clear all active LLAR lockouts on that site.
                        The request is sent directly from this server — no credentials needed on the other end.
                    </p>
                    <div class="cw-unlock-row">
                        <input
                            id="cw-unlock-domain"
                            type="text"
                            list="cw-unlock-datalist"
                            placeholder="example.com"
                            class="regular-text"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <datalist id="cw-unlock-datalist">
                            <?php foreach ($sites as $site) : ?>
                                <option value="<?php echo esc_attr($site['domain']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <button id="cw-unlock-btn" class="button button-primary">
                            Unlock all lockouts
                        </button>
                    </div>
                    <p id="cw-unlock-msg" class="cw-unlock-msg" aria-live="polite"></p>
                    <input type="hidden" id="cw-unlock-nonce" value="<?php echo esc_attr($nonce); ?>">
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<int, array{domain: string, secret: string}>  $sites
     * @param  array{added: int, updated: int, skipped: int}|null  $importResult
     */
    private static function renderSitesCard(array $sites, ?array $importResult = null): void
    {
        $count = count($sites);
        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2>Configured sites</h2>
                <?php if ($count > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php echo $count; ?> site<?php echo $count === 1 ? '' : 's'; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">

                <?php if ($importResult !== null) : ?>
                    <?php
                    $parts = [];
                    if ($importResult['added'])   $parts[] = $importResult['added'] . ' added';
                    if ($importResult['updated'])  $parts[] = $importResult['updated'] . ' updated';
                    if ($importResult['skipped'])  $parts[] = $importResult['skipped'] . ' skipped (invalid)';
                    $summary = $parts ? implode(', ', $parts) : 'nothing to import';
                    ?>
                    <div class="notice notice-success inline" style="margin: 0 0 16px;">
                        <p>Import complete: <?php echo esc_html($summary); ?>.</p>
                    </div>
                <?php endif; ?>

                <?php if (! empty($sites)) : ?>
                    <table class="clockwork-table" style="margin-bottom: 28px;">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Secret</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sites as $site) : ?>
                                <tr>
                                    <td>
                                        <span class="clockwork-data"><?php echo esc_html($site['domain']); ?></span>
                                    </td>
                                    <td>
                                        <span style="color: #9ca3af; letter-spacing: 0.12em; font-family: monospace;">
                                            ••••••••••••••••
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" style="margin: 0;">
                                            <?php wp_nonce_field(self::NONCE_ACTION); ?>
                                            <input type="hidden" name="cw_unlock_action" value="remove">
                                            <input type="hidden" name="domain" value="<?php echo esc_attr($site['domain']); ?>">
                                            <button type="submit" class="button button-small" style="color: #b91c1c; border-color: #fca5a5;">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 style="margin: 0 0 12px; font-size: 13px; font-weight: 600; color: #111827;">
                    <?php echo $count > 0 ? 'Add another site' : 'Add a site'; ?>
                </h3>
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="cw_unlock_action" value="add">
                    <table class="form-table" style="margin: 0;">
                        <tr>
                            <th style="width: 140px;"><label for="cw-add-domain">Domain</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="cw-add-domain"
                                    name="domain"
                                    class="regular-text"
                                    placeholder="example.com"
                                    spellcheck="false"
                                    autocomplete="off"
                                >
                                <p class="description">Hostname only — or paste a full URL, the protocol will be stripped.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cw-add-secret">Companion secret</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="cw-add-secret"
                                    name="secret"
                                    class="regular-text"
                                    placeholder="c14b5b76dd000747…"
                                    spellcheck="false"
                                    autocomplete="off"
                                >
                                <p class="description">
                                    Get it from the site's server:
                                    <code>wp option get clockwork_companion_secret</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p style="margin-top: 14px;">
                        <button type="submit" class="button">Save site</button>
                    </p>
                </form>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">

                <h3 style="margin: 0 0 6px; font-size: 13px; font-weight: 600; color: #111827;">Bulk import</h3>
                <p style="margin: 0 0 10px; color: #6b7280; font-size: 13px;">
                    Paste the Markdown table from the fleet snapshot. The <strong>Site</strong> and
                    <strong>Companion secret</strong> columns are used; the Server column is ignored.
                    Existing entries are updated when the secret has changed.
                </p>
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="cw_unlock_action" value="import">
                    <textarea
                        name="import_data"
                        rows="6"
                        style="width: 100%; font-family: monospace; font-size: 12px; resize: vertical;"
                        placeholder="| site.com | web01.example.com | `abc123…` |
| site2.com | web02.example.com | `def456…` |"
                        spellcheck="false"
                    ></textarea>
                    <p style="margin-top: 10px;">
                        <button type="submit" class="button">Import sites</button>
                    </p>
                </form>

            </div>
        </div>
        <?php
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .cw-unlock-card .clockwork-card__body { padding-top: 4px; }
        .cw-unlock-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .cw-unlock-row #cw-unlock-domain { min-width: 280px; }
        .cw-unlock-msg {
            margin: 12px 0 0;
            font-size: 13px;
            font-weight: 600;
            min-height: 1.4em;
        }
        .cw-unlock-msg--ok  { color: #15803d; }
        .cw-unlock-msg--err { color: #b91c1c; }
        </style>
        <?php
    }

    private static function renderScript(): void
    {
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        (function () {
            var btn    = document.getElementById('cw-unlock-btn');
            if (!btn) return;
            var input  = document.getElementById('cw-unlock-domain');
            var nonce  = document.getElementById('cw-unlock-nonce');
            var msg    = document.getElementById('cw-unlock-msg');
            var ajax   = <?php echo wp_json_encode($ajaxUrl); ?>;
            var action = <?php echo wp_json_encode(self::AJAX_ACTION); ?>;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var domain = input.value.trim();
                if (!domain) {
                    msg.className = 'cw-unlock-msg cw-unlock-msg--err';
                    msg.textContent = 'Enter a domain first.';
                    return;
                }
                btn.disabled = true;
                msg.className = 'cw-unlock-msg';
                msg.textContent = 'Unlocking…';

                var body = new URLSearchParams({ action: action, _nonce: nonce.value, domain: domain });
                fetch(ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var n = data.data.cleared;
                        msg.className = 'cw-unlock-msg cw-unlock-msg--ok';
                        msg.textContent = '✓ Cleared ' + n + ' lockout' + (n === 1 ? '' : 's') + ' on ' + data.data.domain;
                    } else {
                        msg.className = 'cw-unlock-msg cw-unlock-msg--err';
                        msg.textContent = '✗ ' + ((data.data && data.data.message) || 'Unknown error');
                    }
                })
                .catch(function () {
                    msg.className = 'cw-unlock-msg cw-unlock-msg--err';
                    msg.textContent = '✗ Request failed.';
                })
                .finally(function () { btn.disabled = false; });
            });
        })();
        </script>
        <?php
    }
}
