<?php

namespace ClockworkCompanion\Rest;

use ClockworkCompanion\Auth\HmacVerifier;
use ClockworkCompanion\ContactForm\PluginRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/clockwork/v1/detect
 *
 * Returns:
 *   {
 *     "ok": true,
 *     "form_plugin": "contact-form-7" | "wpforms" | "gravityforms" | null,
 *     "forms": [{"id": "...", "title": "...", "page_url": "..." | null}, ...],
 *     "post_smtp": {"active": bool, "version": "..." | null},
 *     "mailer": {"from_email": "...", "from_name": "..."}
 *   }
 *
 * If multiple supported form plugins are active, the first one in
 * PluginRegistry's preference order wins. Clockwork can manually override
 * the choice from the per-site settings tab.
 */
class DetectRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_COMPANION_NAMESPACE, '/detect', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [HmacVerifier::class, 'verify'],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $registry = new PluginRegistry();
        $detected = $registry->detectActive();

        $forms = [];
        if ($detected !== null) {
            $forms = $registry->enumerateForms($detected);
        }

        return new WP_REST_Response([
            'ok' => true,
            'form_plugin' => $detected,
            'forms' => $forms,
            'post_smtp' => $this->postSmtpStatus(),
            'mailer' => [
                'from_email' => (string) get_option('admin_email'),
                'from_name' => (string) get_option('blogname'),
            ],
        ]);
    }

    /**
     * @return array{active: bool, version: ?string}
     */
    private function postSmtpStatus(): array
    {
        $active = is_plugin_active('post-smtp/postman-smtp.php')
            || is_plugin_active('post-smtp/post-smtp.php');

        $version = null;
        if ($active && defined('POST_SMTP_VER')) {
            $version = (string) POST_SMTP_VER;
        }

        return ['active' => $active, 'version' => $version];
    }
}
