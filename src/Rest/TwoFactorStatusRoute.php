<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\TwoFactor\LoginInterceptor;
use ClockworkCompanion\TwoFactor\UserSettings;
use ClockworkCompanion\TwoFactor\WflsMigrator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/two-factor
 *
 * Per-admin/editor 2FA enrollment status, plus the site-level WFLS
 * migration picture. Feeds the monitoring app's "admins without 2FA"
 * issue and the WFLS-deprecation migration tracker.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "gate_disabled": false,            // CLOCKWORK_2FA_DISABLE hatch
 *     "wfls_present": true,              // plugin or leftover data found
 *     "wfls_active": true,               // plugin currently active
 *     "users": [
 *       {
 *         "id": 1,
 *         "login": "aaron",
 *         "role": "administrator",
 *         "state": "clockwork" | "wfls" | "none",
 *         "backup_codes_remaining": 8    // clockwork state only
 *       },
 *       ...
 *     ],
 *     "counts": { "enrolled": 1, "wfls_only": 1, "unprotected": 0, "total": 2 }
 *   }
 *
 * "wfls" state means the user's 2FA lives in Wordfence Login Security and
 * needs migrating; when wfls_active=false those users have NO working
 * login gate despite thinking they do — the monitoring app should treat
 * that as urgent.
 */
class TwoFactorStatusRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/two-factor', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $users = get_users([
            'role__in' => ['administrator', 'editor'],
            'orderby' => 'user_login',
        ]);

        $rows = [];
        $counts = ['enrolled' => 0, 'wfls_only' => 0, 'unprotected' => 0, 'total' => 0];

        foreach ($users as $user) {
            if (UserSettings::isEnabled($user->ID)) {
                $state = 'clockwork';
                $counts['enrolled']++;
            } elseif (WflsMigrator::hasMigratable($user->ID)) {
                $state = 'wfls';
                $counts['wfls_only']++;
            } else {
                $state = 'none';
                $counts['unprotected']++;
            }
            $counts['total']++;

            $row = [
                'id' => (int) $user->ID,
                'login' => (string) $user->user_login,
                'role' => (string) ($user->roles[0] ?? ''),
                'state' => $state,
            ];
            if ($state === 'clockwork') {
                $row['backup_codes_remaining'] = UserSettings::backupCodesRemaining($user->ID);
            }
            $rows[] = $row;
        }

        return [
            'ok' => true,
            'gate_disabled' => LoginInterceptor::isDisabled(),
            'wfls_present' => WflsMigrator::isWflsPresent(),
            'wfls_active' => WflsMigrator::isWflsActive(),
            'users' => $rows,
            'counts' => $counts,
        ];
    }
}
