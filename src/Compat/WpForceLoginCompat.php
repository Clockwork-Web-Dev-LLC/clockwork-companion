<?php

namespace ClockworkCompanion\Compat;

use ClockworkCompanion\Support\RestRequest;

/**
 * Keep the "Force Login" plugin (wp-force-login) from 401'ing Companion's
 * own REST endpoints.
 *
 * Found 2026-08-28 on a client intranet site: wp-force-login hooks
 * `rest_authentication_errors` at priority 99 and rejects any REST request
 * where `is_user_logged_in()` is false — every single Companion request,
 * since Companion authenticates via HMAC signature, not a WP session. The
 * plugin exposes a `v_forcelogin_bypass` filter, but — unlike its own
 * `template_redirect` page-view block — that filter is NOT consulted by its
 * REST handler (`v_forcelogin_rest_access()`); there is no allowlist hook
 * for the REST path at all. Confirmed by reading the plugin source directly
 * rather than assuming: no filter exists to extend, so this class adds the
 * missing hook itself instead of patching wp-force-login's files (fragile —
 * a plugin update would overwrite the change and silently reopen the gap).
 *
 * Same trust model as PerfmattersCompat: this hook does NOT itself perform
 * any authentication. It only stops the blanket 401 from firing before
 * WordPress's REST dispatch reaches route matching, for the `clockwork/v1`
 * namespace specifically. Every Companion route's own `permission_callback`
 * is `HmacVerifier::verify` — that's the real, cryptographic gate, and it
 * still runs immediately afterward exactly as it does on every other site.
 * A request without a valid signature reaches `permission_callback` and is
 * rejected there, same as always. This hook changes nothing about what's
 * actually authorized — it only lets Companion's own check be the one that
 * decides, instead of a hook that can't tell Companion's signed requests
 * apart from a stranger's.
 *
 * Priority 10 (< wp-force-login's 99) so this runs first: once $result is
 * non-null, wp-force-login's own `if (null === $result && ...)` check is
 * false and it returns $result unchanged.
 *
 * The filter receives only the current $result, not the request, so the
 * route comes from RestRequest::dispatchedRoute() — the `rest_route` query
 * var WordPress has already parsed and is about to dispatch. It must be that
 * and nothing looser: returning true here also short-circuits core's own
 * `rest_cookie_check_errors` (priority 100), the check that requires a REST
 * nonce on cookie-authenticated requests. An earlier version matched a
 * substring of REQUEST_URI, so `/wp-json/wp/v2/users?x=/wp-json/clockwork/v1/`
 * skipped the nonce check for core's users endpoint: a link clicked by a
 * logged-in administrator could create another administrator.
 *
 * For the same reason the request is dropped to an anonymous user before
 * returning — exactly what core does for a cookie request with no nonce.
 * Companion's routes authenticate by signature and never use the session.
 *
 * Hook is idempotent and unconditional — if wp-force-login isn't installed
 * the filter simply never fires.
 */
class WpForceLoginCompat
{
    public static function register(): void
    {
        add_filter('rest_authentication_errors', static function ($result) {
            if ($result !== null) {
                return $result;
            }

            if (! RestRequest::inNamespace(RestRequest::dispatchedRoute(), [CLOCKWORK_COMPANION_NAMESPACE])) {
                return $result;
            }

            if (function_exists('wp_set_current_user')) {
                wp_set_current_user(0);
            }

            return true;
        }, 10);
    }
}
