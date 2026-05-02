<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Single source of truth for which form plugins we support and how to detect /
 * enumerate forms in each.
 *
 * Detection order matters — if a site has BOTH CF7 and WPForms active (rare
 * but happens), we pick the first one in this list. Clockwork can override
 * by setting contact_form_plugin manually via the settings UI.
 */
class PluginRegistry
{
    public const PLUGIN_CF7 = 'contact-form-7';
    public const PLUGIN_WPFORMS = 'wpforms';
    public const PLUGIN_GRAVITY = 'gravityforms';

    /**
     * Plugin slug => main file path (relative to wp-content/plugins/).
     * is_plugin_active() needs the main file path, not the slug.
     */
    private const PLUGIN_FILES = [
        self::PLUGIN_CF7 => 'contact-form-7/wp-contact-form-7.php',
        self::PLUGIN_WPFORMS => [
            'wpforms/wpforms.php',
            'wpforms-lite/wpforms.php',
        ],
        self::PLUGIN_GRAVITY => 'gravityforms/gravityforms.php',
    ];

    /**
     * The post types each plugin uses to store form definitions. We enumerate
     * via WP_Query rather than each plugin's API to keep this dependency-light;
     * actual submission still goes through the plugin's own API in the
     * Strategy classes (where validation + side effects matter).
     */
    private const POST_TYPES = [
        self::PLUGIN_CF7 => 'wpcf7_contact_form',
        self::PLUGIN_WPFORMS => 'wpforms',
        // Gravity uses a custom table, not a CPT — handled in enumerateForms()
    ];

    /**
     * Returns the slug of the first supported active form plugin, or null.
     */
    public function detectActive(): ?string
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (self::PLUGIN_FILES as $slug => $files) {
            foreach ((array) $files as $file) {
                if (is_plugin_active($file)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: string, title: string, page_url: ?string}>
     */
    public function enumerateForms(string $plugin): array
    {
        if ($plugin === self::PLUGIN_GRAVITY) {
            return $this->enumerateGravityForms();
        }

        $postType = self::POST_TYPES[$plugin] ?? null;
        if ($postType === null) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $forms = [];
        foreach ($posts as $post) {
            $forms[] = [
                'id' => (string) $post->ID,
                'title' => (string) $post->post_title,
                'page_url' => $this->findHostingPageUrl($plugin, (int) $post->ID),
            ];
        }

        return $forms;
    }

    /**
     * @return array<int, array{id: string, title: string, page_url: ?string}>
     */
    private function enumerateGravityForms(): array
    {
        if (! class_exists('GFAPI')) {
            return [];
        }

        $forms = \GFAPI::get_forms();
        $out = [];
        foreach ($forms as $form) {
            if (empty($form['is_active'])) {
                continue;
            }
            $id = (string) ($form['id'] ?? '');
            $out[] = [
                'id' => $id,
                'title' => (string) ($form['title'] ?? ''),
                'page_url' => $this->findHostingPageUrl(self::PLUGIN_GRAVITY, (int) $id),
            ];
        }

        return $out;
    }

    /**
     * Search published pages for the form's shortcode and return the first
     * matching page's permalink. Best-effort — many sites embed forms in
     * blocks or template parts that this won't catch; the Clockwork UI lets
     * the user paste the URL by hand if auto-detection misses.
     */
    private function findHostingPageUrl(string $plugin, int $formId): ?string
    {
        $shortcode = match ($plugin) {
            self::PLUGIN_CF7 => '[contact-form-7 id="'.$formId.'"',
            self::PLUGIN_WPFORMS => '[wpforms id="'.$formId.'"',
            self::PLUGIN_GRAVITY => '[gravityform id="'.$formId.'"',
            default => null,
        };

        if ($shortcode === null) {
            return null;
        }

        global $wpdb;
        $like = '%'.$wpdb->esc_like($shortcode).'%';
        $postId = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('page', 'post')
               AND post_content LIKE %s
             ORDER BY (post_type = 'page') DESC, post_title ASC
             LIMIT 1",
            $like
        ));

        if (! $postId) {
            return null;
        }

        $url = get_permalink((int) $postId);

        return is_string($url) ? $url : null;
    }
}
