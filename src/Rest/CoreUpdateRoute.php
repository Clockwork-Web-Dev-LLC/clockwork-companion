<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use Core_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/clockwork/v1/wp-core/update
 *
 * Upgrades WordPress core via Core_Upgrader::upgrade(). Major-version bumps
 * (any X.Y change, e.g. 6.8 → 6.9 or 6.9 → 7.0) require explicit confirm_major=true in the body — the
 * Clockwork UI enforces the same gate so this is belt-and-suspenders.
 *
 * Request body:
 *   { "confirm_major": false }
 *
 * Response (200, success):
 *   {
 *     "ok": true,
 *     "before_version": "6.9.4",
 *     "after_version": "7.0",
 *     "messages": ["Downloading WordPress 7.0...", "Unpacking the update..."],
 *     "elapsed_ms": 18432
 *   }
 *
 * Response (200, rejected major):
 *   {
 *     "ok": false,
 *     "error": "major_not_confirmed: ..."
 *   }
 */
class CoreUpdateRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/wp-core/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $confirmMajor = (bool) ($body['confirm_major'] ?? false);

        return new WP_REST_Response($this->run($confirmMajor));
    }

    /**
     * @return array<string, mixed>
     */
    private function run(bool $confirmMajor): array
    {
        $start = microtime(true);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $beforeVersion = (string) get_bloginfo('version');

        // Force a fresh check so the upgrader sees the latest available version.
        wp_version_check([], true);

        $transient = get_site_transient('update_core');

        if (! is_object($transient) || empty($transient->updates)) {
            return [
                'ok'             => true,
                'before_version' => $beforeVersion,
                'after_version'  => $beforeVersion,
                'messages'       => ['WordPress core is already up to date.'],
                'elapsed_ms'     => $this->elapsed($start),
            ];
        }

        $update = self::selectUpdate((array) $transient->updates, $beforeVersion, $confirmMajor);

        if ($update === null) {
            return [
                'ok'             => true,
                'before_version' => $beforeVersion,
                'after_version'  => $beforeVersion,
                'messages'       => ['No supported update found in update_core transient.'],
                'elapsed_ms'     => $this->elapsed($start),
            ];
        }

        $newVersion = (string) $update->version;
        $isMajor    = self::isMajorBump($beforeVersion, $newVersion);

        if ($isMajor && ! $confirmMajor) {
            return $this->fail(
                'major_not_confirmed',
                "Upgrading from {$beforeVersion} to {$newVersion} is a major version bump. "
                    . 'Send confirm_major=true to proceed.',
                $start
            );
        }

        if (! WP_Filesystem()) {
            return $this->fail('filesystem_init_failed', 'WP_Filesystem could not be initialised.', $start);
        }

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $result   = $upgrader->upgrade($update, ['attempt_rollback' => true]);

        $messages = $this->collectMessages($skin);

        if ($result === false || is_wp_error($result)) {
            $error = is_wp_error($result) ? $result->get_error_message() : 'Core_Upgrader::upgrade returned false.';

            return $this->fail('upgrade_failed', $error, $start, $messages);
        }

        // After upgrade, re-read the version from wp-includes/version.php.
        $afterVersion = $beforeVersion;
        $versionFile  = ABSPATH . WPINC . '/version.php';
        if (file_exists($versionFile)) {
            $wp_version = '';
            include $versionFile;
            if ($wp_version !== '') {
                $afterVersion = (string) $wp_version;
            }
        }

        return [
            'ok'             => true,
            'before_version' => $beforeVersion,
            'after_version'  => $afterVersion,
            'messages'       => $messages,
            'elapsed_ms'     => $this->elapsed($start),
        ];
    }

    /**
     * Choose the offer to install from update_core's list.
     *
     * Without confirm_major: the newest offer on the site's current X.Y
     * branch (preferring core's 'autoupdate' offer at equal versions), so a
     * site on an older branch still gets its minor/security release instead
     * of being stopped at major_not_confirmed. Only when its branch has
     * nothing newer is the newest offer overall returned — which then needs
     * confirmation. With confirm_major: the newest offer overall.
     * 'development' and other offer types are never chosen.
     *
     * @param  array<int, mixed>  $offers
     */
    public static function selectUpdate(array $offers, string $current, bool $confirmMajor): ?object
    {
        $branch = self::branch($current);
        $best = null;
        $bestInBranch = null;

        foreach ($offers as $offer) {
            if (! is_object($offer) || ! isset($offer->version)) {
                continue;
            }
            $response = (string) ($offer->response ?? '');
            if (! in_array($response, ['autoupdate', 'upgrade'], true)) {
                continue;
            }
            $version = (string) $offer->version;
            if (! version_compare($version, $current, '>')) {
                continue;
            }

            if ($best === null || self::better($offer, $best)) {
                $best = $offer;
            }
            if (self::branch($version) === $branch && ($bestInBranch === null || self::better($offer, $bestInBranch))) {
                $bestInBranch = $offer;
            }
        }

        if ($confirmMajor) {
            return $best;
        }

        return $bestInBranch ?? $best;
    }

    /** Higher version wins; at the same version an 'autoupdate' offer wins. */
    private static function better(object $a, object $b): bool
    {
        $cmp = version_compare((string) $a->version, (string) $b->version);
        if ($cmp !== 0) {
            return $cmp > 0;
        }

        return ($a->response ?? '') === 'autoupdate' && ($b->response ?? '') !== 'autoupdate';
    }

    /**
     * WordPress's major releases are X.Y (6.8 → 6.9 is a major update), so
     * anything that changes X.Y needs confirm_major.
     */
    public static function isMajorBump(string $from, string $to): bool
    {
        return version_compare(self::branch($to), self::branch($from), '>');
    }

    private static function branch(string $version): string
    {
        $parts = explode('.', $version);

        return (int) ($parts[0] ?? 0).'.'.(int) ($parts[1] ?? 0);
    }

    /** @param array<int, string> $messages */
    private function fail(string $code, string $message, float $start, array $messages = []): array
    {
        return [
            'ok'             => false,
            'before_version' => '',
            'after_version'  => null,
            'messages'       => $messages,
            'elapsed_ms'     => $this->elapsed($start),
            'error'          => "{$code}: {$message}",
        ];
    }

    private function collectMessages(WP_Ajax_Upgrader_Skin $skin): array
    {
        $messages = [];
        if (method_exists($skin, 'get_upgrade_messages')) {
            foreach ((array) $skin->get_upgrade_messages() as $m) {
                $messages[] = (string) wp_strip_all_tags((string) $m);
            }
        }
        if ($skin->get_errors()->has_errors()) {
            foreach ($skin->get_errors()->get_error_messages() as $m) {
                $messages[] = 'Error: ' . wp_strip_all_tags((string) $m);
            }
        }

        return $messages;
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
