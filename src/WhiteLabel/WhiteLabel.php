<?php

namespace ClockworkCompanion\WhiteLabel;

use ClockworkCompanion\Admin\Menu;

class WhiteLabel
{
    public const OPTION_KEY = 'clockwork_white_label';
    public const DONATED_AT_OPTION = 'clockwork_whitelabel_donated_at';
    public const DISMISSED_AT_OPTION = 'clockwork_whitelabel_donate_dismissed_at';

    /**
     * Default white-label branding configuration.
     */
    public const DEFAULTS = [
        'enabled' => false,
        'plugin_name' => 'Clockwork Companion',
        'plugin_description' => 'Companion mu-plugin for the Clockwork monitoring app. Exposes signed REST endpoints under /wp-json/clockwork/v1/ for fleet-wide control of WordPress maintenance tasks.',
        'author_name' => 'Clockwork Web Dev, LLC',
        'author_url' => 'https://www.clockworkwp.com',
        'plugin_url' => 'https://www.clockworkwp.com',
        'menu_title' => 'Clockwork',
        'brand_text' => 'Companion',
        'logo_url' => '',
        'menu_icon_url' => '',
        'hide_version' => false,
        'primary_color' => '#6953C4',
        'primary_dark_color' => '#2D2062',
        'primary_soft_color' => '#D1C9F4',
        'accent_color' => '#7EFF83',
        'page_bg_color' => '#FFFFFF',
        'card_bg_color' => '#FFFFFF',
        'support_button_label' => 'Get Support',
        'support_url' => '',
    ];

    /**
     * Boot white labeling hooks and filters.
     */
    public function boot(): void
    {
        // Metadata filtering for regular plugins and mu-plugins
        add_filter('all_plugins', [$this, 'filterAllPlugins']);
        add_filter('show_advanced_plugins', [$this, 'filterAdvancedPlugins'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'filterPluginRowMeta'], 10, 2);

        // Dynamic CSS variables injection into admin pages
        add_action('admin_head', [$this, 'injectBrandingCss']);

        // Form post and AJAX handlers for settings and donation state
        add_action('admin_post_clockwork_whitelabel_save', [$this, 'handleSaveSettings']);
        add_action('admin_post_clockwork_whitelabel_donated', [$this, 'handleDonatedAction']);
        add_action('admin_post_clockwork_whitelabel_dismiss_donation', [$this, 'handleDismissDonationAction']);
        add_action('wp_ajax_clockwork_whitelabel_donated', [$this, 'handleAjaxDonated']);
        add_action('wp_ajax_clockwork_whitelabel_dismiss_donation', [$this, 'handleAjaxDismissDonation']);
    }

    /**
     * Retrieve all white label settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::DEFAULTS, $saved);
    }

    /**
     * Check if white labeling is enabled.
     */
    public static function isEnabled(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['enabled']);
    }

    /**
     * Get the active plugin name.
     */
    public static function getPluginName(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['plugin_name']))
            ? (string) $settings['plugin_name']
            : self::DEFAULTS['plugin_name'];
    }

    /**
     * Get the active author name.
     */
    public static function getAuthorName(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['author_name']))
            ? (string) $settings['author_name']
            : self::DEFAULTS['author_name'];
    }

    /**
     * Get the active menu title.
     */
    public static function getMenuTitle(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['menu_title']))
            ? (string) $settings['menu_title']
            : self::DEFAULTS['menu_title'];
    }

    /**
     * Get the active brand header text.
     */
    public static function getBrandText(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['brand_text']))
            ? (string) $settings['brand_text']
            : self::DEFAULTS['brand_text'];
    }

    /**
     * Get the active logo URL (falling back to default bundle logo).
     */
    public static function getLogoUrl(): string
    {
        $settings = self::getSettings();
        if (!empty($settings['enabled']) && !empty($settings['logo_url'])) {
            return (string) $settings['logo_url'];
        }

        return plugins_url('assets/clockwork-logo.png', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php');
    }

    /**
     * Whether the version indicator is visible in the header.
     */
    public static function isVersionVisible(): bool
    {
        $settings = self::getSettings();
        return empty($settings['enabled']) || empty($settings['hide_version']);
    }

    /**
     * Get the support button label.
     */
    public static function getSupportButtonLabel(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['support_button_label']))
            ? (string) $settings['support_button_label']
            : self::DEFAULTS['support_button_label'];
    }

    /**
     * Get the custom support URL, if specified.
     */
    public static function getSupportUrl(): string
    {
        $settings = self::getSettings();
        return (!empty($settings['enabled']) && !empty($settings['support_url']))
            ? (string) $settings['support_url']
            : '';
    }

    /**
     * Check if the donation prompt should be shown.
     * Hidden if user clicked "I donated" within the last 365 days,
     * or clicked "Remind me later" within the last 90 days.
     */
    public static function isDonationPromptVisible(): bool
    {
        $donatedAt = get_option(self::DONATED_AT_OPTION, 0);
        if ($donatedAt && (time() - (int) $donatedAt) < (365 * 86400)) {
            return false;
        }

        $dismissedAt = get_option(self::DISMISSED_AT_OPTION, 0);
        if ($dismissedAt && (time() - (int) $dismissedAt) < (90 * 86400)) {
            return false;
        }

        return true;
    }

    /**
     * Get donation state information for UI display.
     *
     * @return array{has_donated: bool, donated_at: int, next_nag_at: int, days_remaining: int}
     */
    public static function getDonationState(): array
    {
        $donatedAt = (int) get_option(self::DONATED_AT_OPTION, 0);
        $hasDonated = $donatedAt > 0 && (time() - $donatedAt) < (365 * 86400);
        $nextNagAt = $donatedAt ? ($donatedAt + (365 * 86400)) : 0;
        $daysRemaining = $nextNagAt ? max(0, (int) ceil(($nextNagAt - time()) / 86400)) : 0;

        return [
            'has_donated' => $hasDonated,
            'donated_at' => $donatedAt,
            'next_nag_at' => $nextNagAt,
            'days_remaining' => $daysRemaining,
        ];
    }

    /**
     * Filter standard plugins list (plugins.php).
     *
     * @param array<string, array<string, mixed>> $plugins
     * @return array<string, array<string, mixed>>
     */
    public function filterAllPlugins(array $plugins): array
    {
        if (!self::isEnabled()) {
            return $plugins;
        }

        $settings = self::getSettings();

        foreach ($plugins as $file => &$data) {
            if ($this->isCompanionPluginFile($file)) {
                if (!empty($settings['plugin_name'])) {
                    $data['Name'] = (string) $settings['plugin_name'];
                    $data['Title'] = (string) $settings['plugin_name'];
                }
                if (!empty($settings['plugin_description'])) {
                    $data['Description'] = (string) $settings['plugin_description'];
                }
                if (!empty($settings['author_name'])) {
                    $data['Author'] = (string) $settings['author_name'];
                    $data['AuthorName'] = (string) $settings['author_name'];
                }
                if (!empty($settings['author_url'])) {
                    $data['AuthorURI'] = (string) $settings['author_url'];
                }
                if (!empty($settings['plugin_url'])) {
                    $data['PluginURI'] = (string) $settings['plugin_url'];
                }
            }
        }

        return $plugins;
    }

    /**
     * Filter advanced / Must-Use plugins list (plugins.php?plugin_status=mustuse).
     *
     * @param array<string, array<string, mixed>> $plugins
     * @param string $type
     * @return array<string, array<string, mixed>>
     */
    public function filterAdvancedPlugins(array $plugins, string $type = 'mustuse'): array
    {
        if ($type !== 'mustuse' || !self::isEnabled()) {
            return $plugins;
        }

        return $this->filterAllPlugins($plugins);
    }

    /**
     * Filter plugin row meta links under the plugin entry.
     *
     * @param string[] $meta
     * @param string $file
     * @return string[]
     */
    public function filterPluginRowMeta(array $meta, string $file): array
    {
        if (!self::isEnabled() || !$this->isCompanionPluginFile($file)) {
            return $meta;
        }

        $settings = self::getSettings();
        if (empty($settings['author_name'])) {
            return $meta;
        }

        $authorName = esc_html((string) $settings['author_name']);
        $authorUrl = !empty($settings['author_url']) ? esc_url((string) $settings['author_url']) : '';

        // Rebuild or update author meta link if present
        $updated = [];
        foreach ($meta as $item) {
            if (stripos($item, 'By ') !== false || stripos($item, 'author') !== false) {
                if (!empty($authorUrl)) {
                    $updated[] = sprintf('By <a href="%s">%s</a>', $authorUrl, $authorName);
                } else {
                    $updated[] = sprintf('By %s', $authorName);
                }
            } else {
                $updated[] = $item;
            }
        }

        return $updated;
    }

    /**
     * Check if a plugin file path points to the Clockwork Companion.
     */
    protected function isCompanionPluginFile(string $file): bool
    {
        return str_contains($file, 'clockwork-companion.php') ||
               str_contains($file, 'clockwork-companion/') ||
               str_ends_with($file, 'clockwork-companion.php');
    }

    /**
     * Inject custom CSS variables and overrides into wp-admin head.
     */
    public function injectBrandingCss(): void
    {
        $hook = $GLOBALS['hook_suffix'] ?? '';
        $page = $_GET['page'] ?? '';

        // Inject on all Clockwork admin pages, or if custom menu icon is defined
        $isClockworkPage = str_contains((string) $hook, Menu::SLUG) || str_starts_with((string) $page, 'clockwork');

        $settings = self::getSettings();
        $primary = $this->sanitizeHex($settings['primary_color'] ?? '#6953C4');
        $primaryDark = $this->sanitizeHex($settings['primary_dark_color'] ?? '#2D2062');
        $primarySoft = $this->sanitizeHex($settings['primary_soft_color'] ?? '#D1C9F4');
        $accent = $this->sanitizeHex($settings['accent_color'] ?? '#7EFF83');
        $pageBg = $this->sanitizeHex($settings['page_bg_color'] ?? '#FFFFFF');
        $cardBg = $this->sanitizeHex($settings['card_bg_color'] ?? '#FFFFFF');

        $menuIcon = !empty($settings['enabled']) && !empty($settings['menu_icon_url'])
            ? esc_url((string) $settings['menu_icon_url'])
            : '';

        echo "<style id=\"clockwork-whitelabel-styles\">\n";

        if ($isClockworkPage) {
            echo ":root {\n";
            echo "    --cwk-primary: {$primary};\n";
            echo "    --cwk-primary-dark: {$primaryDark};\n";
            echo "    --cwk-primary-soft: {$primarySoft};\n";
            echo "    --cwk-accent: {$accent};\n";
            echo "    --cwk-page-bg: {$pageBg};\n";
            echo "    --cwk-card: {$cardBg};\n";
            echo "}\n";
        }

        if (!empty($menuIcon)) {
            echo "#adminmenu #toplevel_page_clockwork .wp-menu-image img {\n";
            echo "    content: url('{$menuIcon}') !important;\n";
            echo "    width: 20px !important;\n";
            echo "    height: 20px !important;\n";
            echo "}\n";
        }

        echo "</style>\n";
    }

    /**
     * Sanitize a hex color string.
     */
    protected function sanitizeHex(string $color, string $default = '#6953C4'): string
    {
        $color = trim($color);
        if (preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color)) {
            return $color;
        }
        return $default;
    }

    /**
     * Handle POST form submission to save white label settings.
     */
    public function handleSaveSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_save', 'clockwork_whitelabel_nonce');

        $input = $_POST['whitelabel'] ?? [];
        if (!is_array($input)) {
            $input = [];
        }

        $clean = [
            'enabled' => !empty($input['enabled']),
            'plugin_name' => sanitize_text_field($input['plugin_name'] ?? self::DEFAULTS['plugin_name']),
            'plugin_description' => sanitize_textarea_field($input['plugin_description'] ?? self::DEFAULTS['plugin_description']),
            'author_name' => sanitize_text_field($input['author_name'] ?? self::DEFAULTS['author_name']),
            'author_url' => esc_url_raw($input['author_url'] ?? ''),
            'plugin_url' => esc_url_raw($input['plugin_url'] ?? ''),
            'menu_title' => sanitize_text_field($input['menu_title'] ?? self::DEFAULTS['menu_title']),
            'brand_text' => sanitize_text_field($input['brand_text'] ?? self::DEFAULTS['brand_text']),
            'logo_url' => esc_url_raw($input['logo_url'] ?? ''),
            'menu_icon_url' => esc_url_raw($input['menu_icon_url'] ?? ''),
            'hide_version' => !empty($input['hide_version']),
            'primary_color' => $this->sanitizeHex($input['primary_color'] ?? '#6953C4'),
            'primary_dark_color' => $this->sanitizeHex($input['primary_dark_color'] ?? '#2D2062'),
            'primary_soft_color' => $this->sanitizeHex($input['primary_soft_color'] ?? '#D1C9F4'),
            'accent_color' => $this->sanitizeHex($input['accent_color'] ?? '#7EFF83'),
            'page_bg_color' => $this->sanitizeHex($input['page_bg_color'] ?? '#FFFFFF'),
            'card_bg_color' => $this->sanitizeHex($input['card_bg_color'] ?? '#FFFFFF'),
            'support_button_label' => sanitize_text_field($input['support_button_label'] ?? 'Get Support'),
            'support_url' => esc_url_raw($input['support_url'] ?? ''),
        ];

        update_option(self::OPTION_KEY, $clean);

        $redirectUrl = add_query_arg([
            'page' => 'clockwork-branding',
            'saved' => '1',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Handle "I Donated" operator trigger.
     */
    public function handleDonatedAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_donated', 'nonce');

        update_option(self::DONATED_AT_OPTION, time());

        $redirectUrl = add_query_arg([
            'page' => 'clockwork-branding',
            'donated' => '1',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Handle "Remind me later" donation dismiss trigger.
     */
    public function handleDismissDonationAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_dismiss', 'nonce');

        update_option(self::DISMISSED_AT_OPTION, time());

        $redirectUrl = add_query_arg([
            'page' => 'clockwork-branding',
            'dismissed' => '1',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * AJAX handler for "I Donated".
     */
    public function handleAjaxDonated(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('clockwork_whitelabel_donated', 'nonce');

        $now = time();
        update_option(self::DONATED_AT_OPTION, $now);

        wp_send_json_success([
            'message' => 'Thank you so much for supporting Clockwork! The reminder is snoozed for 1 year.',
            'donated_at' => $now,
            'next_nag_at' => $now + (365 * 86400),
        ]);
    }

    /**
     * AJAX handler for dismiss donation nag.
     */
    public function handleAjaxDismissDonation(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('clockwork_whitelabel_dismiss', 'nonce');

        $now = time();
        update_option(self::DISMISSED_AT_OPTION, $now);

        wp_send_json_success([
            'message' => 'Donation reminder snoozed for 90 days.',
            'dismissed_at' => $now,
        ]);
    }
}
