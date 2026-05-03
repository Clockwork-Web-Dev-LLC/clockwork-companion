<?php

namespace ClockworkCompanion\Admin\Actions;

use ClockworkCompanion\Admin\Pages\SecurityPage;
use ClockworkCompanion\SecurityScans\ChecksumsRunner;
use ClockworkCompanion\SecurityScans\SitecheckScanner;

/**
 * admin-post.php handler for the "Run scan now" buttons on the Security page.
 *
 * One handler, one query param ($_POST['scan_type']) discriminates between
 * Sucuri and core checksums. Both are synchronous from the browser's POV —
 * Sucuri takes ~1s, core checksums vary (5-30s for the PHP-side path on a
 * typical install) — wp_die'ing in a try/catch keeps slowness from leaving
 * the user on a confusing blank screen.
 *
 * Auth model: WordPress nonce + manage_options capability. Same as any other
 * admin form. We do NOT use the HMAC verifier here — that's for Clockwork
 * → Companion machine-to-machine calls, not for a human admin clicking a
 * button in their wp-admin.
 */
class RunSecurityScanAction
{
    public const ACTION_HOOK = 'clockwork_run_security_scan';

    public const NONCE_ACTION = 'clockwork_run_security_scan';

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_HOOK, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('You do not have permission to run security scans on this site.', 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Invalid request — please return to the Security page and try again.', 400);
        }

        $type = isset($_POST['scan_type']) ? (string) $_POST['scan_type'] : '';

        switch ($type) {
            case 'sitecheck':
                $message = (new SitecheckScanner())->runAndPersist();
                break;

            case 'core_checksums':
                if (! ChecksumsRunner::isAvailable()) {
                    $message = 'Core checksum verification is not available on this host.';
                    break;
                }
                $message = (new ChecksumsRunner())->runAndPersist();
                break;

            default:
                wp_die('Unknown scan type.', 400);
        }

        $redirect = add_query_arg(
            [
                'page' => SecurityPage::SLUG,
                'flash' => rawurlencode($message),
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }
}
