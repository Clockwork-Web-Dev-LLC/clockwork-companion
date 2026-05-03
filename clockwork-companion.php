<?php
/**
 * Plugin Name: Clockwork Companion
 * Description: Companion mu-plugin for the Clockwork monitoring app. Exposes signed REST endpoints under /wp-json/clockwork/v1/ for fleet-wide control of WordPress maintenance tasks (contact-form testing, plugin updates, security scans, etc.).
 * Version: 1.11.1
 * Author: Clockwork
 * License: Proprietary
 *
 * Designed to live as a true mu-plugin at wp-content/mu-plugins/clockwork-companion.php
 * with the rest of the source loaded from wp-content/mu-plugins/clockwork-companion/.
 * This file is the loader; do not put logic here.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CLOCKWORK_COMPANION_VERSION', '1.11.1');
define('CLOCKWORK_COMPANION_DIR', __DIR__ . '/clockwork-companion');
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
