<?php
/**
 * Plugin Name: Clockwork Companion
 * Description: Companion mu-plugin for the Clockwork monitoring app. Exposes signed REST endpoints under /wp-json/clockwork/v1/ for fleet-wide control of WordPress maintenance tasks (contact-form testing, plugin updates, security scans, etc.).
 * Version: 1.34.0
 * Author: Clockwork Web Dev, LLC
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 * Designed to live as a true mu-plugin at wp-content/mu-plugins/clockwork-companion.php
 * with the rest of the source loaded from wp-content/mu-plugins/clockwork-companion/.
 * This file is the loader; do not put logic here.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CLOCKWORK_COMPANION_VERSION', '1.34.0');
define('CLOCKWORK_COMPANION_DIR', __DIR__ . '/clockwork-companion');
define('CLOCKWORK_COMPANION_NAMESPACE', 'clockwork/v1');

// Support form — proxies to the Gravity Forms REST API v2 on the operator's
// own site. Point CLOCKWORK_SUPPORT_SITE_URL at that site in wp-config.php and
// supply credentials from its Forms → Settings → REST API → Authentication
// screen (API version 2):
//   CLOCKWORK_SUPPORT_GF_KEY    — Consumer Key  (ck_…)
//   CLOCKWORK_SUPPORT_GF_SECRET — Consumer Secret (cs_…)
// Left undefined, the in-plugin support form is simply unavailable.
if (! defined('CLOCKWORK_SUPPORT_SITE_URL')) {
    define('CLOCKWORK_SUPPORT_SITE_URL', '');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'ClockworkCompanion\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = CLOCKWORK_COMPANION_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', function (): void {
    (new ClockworkCompanion\Plugin())->boot();
}, 5);
