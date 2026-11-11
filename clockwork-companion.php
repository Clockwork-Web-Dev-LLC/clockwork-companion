<?php
/**
 * Plugin Name: Clockwork Companion
 * Plugin URI: https://clockworkcontrol.com
 * Description: Companion plugin for the Clockwork monitoring app. Exposes signed REST endpoints under /wp-json/clockwork/v1/ for fleet-wide control of WordPress maintenance tasks (contact-form testing, plugin updates, security scans, etc.).
 * Version: 1.43.2
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Clockwork Web Dev, LLC
 * Author URI: https://clockworkcontrol.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Update URI: https://github.com/Clockwork-Web-Dev-LLC/clockwork-companion
 *
 * Installed as a standard plugin at wp-content/plugins/clockwork-companion/clockwork-companion.php
 * (with legacy support for wp-content/mu-plugins/clockwork-companion.php).
 * This file is the loader; do not put logic here.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (defined('CLOCKWORK_COMPANION_VERSION')) {
    // If an older mu-plugin copy loaded earlier in this request while this
    // standard plugin copy is also active, remove the legacy mu-plugin
    // loader on shutdown so the next request cleanly boots from plugins/.
    if (function_exists('add_action')) {
        add_action('shutdown', static function (): void {
            if (defined('WPMU_PLUGIN_DIR') && is_file(WPMU_PLUGIN_DIR . '/clockwork-companion.php')) {
                @unlink(WPMU_PLUGIN_DIR . '/clockwork-companion.php');
            }
        });
    }

    return;
}

define('CLOCKWORK_COMPANION_VERSION', '1.43.2');
if (! defined('CLOCKWORK_COMPANION_DIR')) {
    if (is_dir(__DIR__ . '/src')) {
        define('CLOCKWORK_COMPANION_DIR', __DIR__);
    } else {
        define('CLOCKWORK_COMPANION_DIR', __DIR__ . '/clockwork-companion');
    }
}
if (! defined('CLOCKWORK_COMPANION_FILE')) {
    define('CLOCKWORK_COMPANION_FILE', __FILE__);
}
define('CLOCKWORK_COMPANION_NAMESPACE', 'clockwork/v1');

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
