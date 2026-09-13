<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for remote plugin lifecycle management (toggle, delete, install).
 *
 * POST /wp-json/clockwork-renegade/v1/plugins/toggle
 * POST /wp-json/clockwork-renegade/v1/plugins/delete
 * POST /wp-json/clockwork-renegade/v1/plugins/install
 */
class PluginLifecycleRoute
{
    /**
     * File slug shape: 'directory/file.php' or 'single-file.php'.
     */
    private const FILE_SLUG_PATTERN = '~^(?:[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*\.php$~i';

    /**
     * WordPress.org directory repo slug: e.g. 'classic-editor', 'yoast-seo'.
     */
    private const REPO_SLUG_PATTERN = '~^[a-z0-9][a-z0-9._-]*$~i';

    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/plugins/toggle', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleToggle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/plugins/delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleDelete'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);

        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/plugins/install', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleInstall'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handleToggle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $slug = isset($body['slug']) ? (string) $body['slug'] : '';
        if ($slug === '' || ! preg_match(self::FILE_SLUG_PATTERN, $slug)) {
            return new WP_Error('invalid_input', 'A valid plugin slug (directory/file.php) is required', ['status' => 400]);
        }

        $action = isset($body['action']) ? strtolower((string) $body['action']) : '';
        if (! in_array($action, ['activate', 'deactivate'], true)) {
            return new WP_Error('invalid_input', "Action must be 'activate' or 'deactivate'", ['status' => 400]);
        }

        $networkWide = ! empty($body['network_wide']);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all = get_plugins();
        if (! isset($all[$slug])) {
            return new WP_Error('plugin_not_found', "Plugin '{$slug}' is not installed", ['status' => 404]);
        }

        if ($action === 'activate') {
            $result = activate_plugin($slug, '', $networkWide, false);
            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'slug'  => $slug,
                    'error' => $result->get_error_message(),
                ], 400);
            }
        } else {
            deactivate_plugins($slug, false, $networkWide);
        }

        $isActive = is_plugin_active($slug) || (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($slug));

        return new WP_REST_Response([
            'ok'           => true,
            'slug'         => $slug,
            'action'       => $action,
            'active'       => $isActive,
            'network_wide' => $networkWide,
        ]);
    }

    public function handleDelete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $slug = isset($body['slug']) ? (string) $body['slug'] : '';
        if ($slug === '' || ! preg_match(self::FILE_SLUG_PATTERN, $slug)) {
            return new WP_Error('invalid_input', 'A valid plugin slug (directory/file.php) is required', ['status' => 400]);
        }

        $networkWide = ! empty($body['network_wide']);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $all = get_plugins();
        if (! isset($all[$slug])) {
            return new WP_Error('plugin_not_found', "Plugin '{$slug}' is not installed", ['status' => 404]);
        }

        // Deactivate first if active before deleting
        if (is_plugin_active($slug) || (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($slug))) {
            deactivate_plugins($slug, true, $networkWide);
        }

        if (! WP_Filesystem()) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => 'WP_Filesystem initialization failed',
            ], 500);
        }

        $result = delete_plugins([$slug]);
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => $result->get_error_message(),
            ], 400);
        }

        if ($result === false) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => 'Failed to delete plugin files',
            ], 500);
        }

        return new WP_REST_Response([
            'ok'      => true,
            'slug'    => $slug,
            'deleted' => true,
        ]);
    }

    public function handleInstall(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            $body = [];
        }

        $slug = isset($body['slug']) ? trim((string) $body['slug']) : '';
        if ($slug === '' || ! preg_match(self::REPO_SLUG_PATTERN, $slug)) {
            return new WP_Error('invalid_input', 'A valid WordPress.org plugin repository slug is required', ['status' => 400]);
        }

        $activate = ! empty($body['activate']);
        $networkWide = ! empty($body['network_wide']);

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $api = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => [
                'short_description' => false,
                'sections'          => false,
                'requires'          => true,
                'tested'            => true,
                'downloaded'        => false,
            ],
        ]);

        if (is_wp_error($api)) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => $api->get_error_message(),
            ], 400);
        }

        if (empty($api->download_link)) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => 'No download package found for this plugin',
            ], 400);
        }

        if (! WP_Filesystem()) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => 'WP_Filesystem initialization failed',
            ], 500);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $installed = $upgrader->install($api->download_link);

        if (is_wp_error($installed)) {
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => $installed->get_error_message(),
            ], 400);
        }

        if ($installed === false) {
            $errors = $skin->get_errors();
            $errorMessage = $errors->has_errors() ? $errors->get_error_message() : 'Plugin installation failed';
            return new WP_REST_Response([
                'ok'    => false,
                'slug'  => $slug,
                'error' => $errorMessage,
            ], 500);
        }

        $pluginFile = $upgrader->plugin_info();
        $activated = false;

        if ($activate && ! empty($pluginFile)) {
            $actResult = activate_plugin($pluginFile, '', $networkWide, false);
            $activated = ! is_wp_error($actResult);
        }

        return new WP_REST_Response([
            'ok'          => true,
            'slug'        => $slug,
            'plugin_file' => $pluginFile,
            'version'     => (string) ($api->version ?? ''),
            'activated'   => $activated,
        ]);
    }
}
