<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/admins
 *
 * Lists users with the administrator role. Useful for security auditing —
 * detects rogue admins added by a compromised plugin, ex-employee accounts
 * still active, or default `admin` usernames.
 *
 * "Last login" is NOT natively tracked by WordPress. We surface the values
 * we can read cheaply (`session_tokens` user-meta, populated by core's
 * session manager) but they're best-effort.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "admins": [
 *       {
 *         "id": 1,
 *         "login": "aaron",
 *         "email": "aaron@example.com",
 *         "display_name": "Aaron",
 *         "registered_at": "2024-01-15T12:00:00+00:00",
 *         "last_seen_at": "2026-05-01T18:23:00+00:00" | null,
 *         "active_sessions": 2
 *       },
 *       ...
 *     ],
 *     "count": 3,
 *     "default_admin_present": false
 *   }
 */
class AdminsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/admins', [
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
            'role' => 'administrator',
            'orderby' => 'registered',
            'order' => 'ASC',
        ]);

        $admins = [];
        $defaultAdminPresent = false;

        foreach ($users as $user) {
            if (strtolower((string) $user->user_login) === 'admin') {
                $defaultAdminPresent = true;
            }

            $sessions = get_user_meta($user->ID, 'session_tokens', true);
            $sessionCount = is_array($sessions) ? count($sessions) : 0;
            $lastSeen = $this->lastSessionTimestamp($sessions);

            $admins[] = [
                'id' => (int) $user->ID,
                'login' => (string) $user->user_login,
                'email' => (string) $user->user_email,
                'display_name' => (string) $user->display_name,
                'registered_at' => $this->mysqlToIso((string) $user->user_registered),
                'last_seen_at' => $lastSeen,
                'active_sessions' => $sessionCount,
            ];
        }

        return [
            'ok' => true,
            'admins' => $admins,
            'count' => count($admins),
            'default_admin_present' => $defaultAdminPresent,
        ];
    }

    private function lastSessionTimestamp(mixed $sessions): ?string
    {
        if (! is_array($sessions) || $sessions === []) {
            return null;
        }

        $newest = 0;
        foreach ($sessions as $session) {
            if (is_array($session) && isset($session['login']) && is_numeric($session['login'])) {
                $newest = max($newest, (int) $session['login']);
            }
        }

        return $newest > 0 ? gmdate('c', $newest) : null;
    }

    private function mysqlToIso(string $value): ?string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($value . ' UTC');
        return $ts === false ? null : gmdate('c', $ts);
    }
}
