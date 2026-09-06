<?php

namespace ClockworkCompanion\WhiteLabel;

use ClockworkCompanion\Admin\Menu;

class WhiteLabel
{
    public const OPTION_KEY = 'clockwork_companion_branding';
    public const LEGACY_OPTION_KEY = 'clockwork_white_label';
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
        'company_name' => 'Clockwork Web Dev, LLC',
        'author_url' => 'https://www.clockworkwd.com',
        'company_url' => 'https://www.clockworkwd.com',
        'plugin_url' => 'https://www.clockworkwd.com',
        'support_email' => 'support@clockworkcontrol.com',
        'support_url' => '',
        // Comma/newline-separated email domains whose users see the admin
        // menu. Empty means no gating — every administrator sees it, which is
        // the right default for a single-agency install. See
        // Menu::currentUserIsAgency().
        'agency_email_domains' => '',
        'menu_title' => 'Clockwork',
        'menu_icon' => '',
        'brand_text' => 'Companion',
        'logo_url' => '',
        'menu_icon_url' => '',
        'hide_plugin_row' => false,
        'hide_help_links' => false,
        'footer_text' => '',
        'hide_version' => false,
        'primary_color' => '#6953C4',
        'primary_dark_color' => '#2D2062',
        'primary_soft_color' => '#D1C9F4',
        'accent_color' => '#7EFF83',
        'page_bg_color' => '#FFFFFF',
        'card_bg_color' => '#FFFFFF',
        'support_button_label' => 'Get Support',
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
        add_filter('admin_footer_text', [$this, 'filterAdminFooterText'], 20);

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
        $saved = get_option(self::OPTION_KEY, null);
        if (! is_array($saved)) {
            $saved = get_option(self::LEGACY_OPTION_KEY, []);
        }
        if (! is_array($saved)) {
            $saved = [];
        }

        $merged = array_merge(self::DEFAULTS, $saved);

        // Synchronize company_name/author_name & company_url/author_url
        if (! empty($merged['company_name'])) {
            $merged['author_name'] = $merged['company_name'];
        } elseif (! empty($merged['author_name'])) {
            $merged['company_name'] = $merged['author_name'];
        }

        if (! empty($merged['company_url'])) {
            $merged['author_url'] = $merged['company_url'];
            $merged['plugin_url'] = $merged['company_url'];
        } elseif (! empty($merged['author_url'])) {
            $merged['company_url'] = $merged['author_url'];
            $merged['plugin_url'] = $merged['author_url'];
        }

        if (! empty($merged['menu_title'])) {
            $merged['brand_text'] = $merged['menu_title'];
        }

        return $merged;
    }

    /**
     * Check if white labeling is enabled.
     */
    public static function isEnabled(): bool
    {
        $settings = self::getSettings();

        return ! empty($settings['enabled']);
    }

    /**
     * Get the active plugin name.
     */
    public static function getPluginName(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['plugin_name']))
            ? (string) $settings['plugin_name']
            : self::DEFAULTS['plugin_name'];
    }

    /**
     * Get the active author/company name.
     */
    public static function getAuthorName(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['author_name']))
            ? (string) $settings['author_name']
            : self::DEFAULTS['author_name'];
    }

    /**
     * Render $label as a link to the configured support destination, or as
     * plain text when no support URL is set.
     *
     * Client-facing pages use this instead of hard-coding an agency's URL, so
     * a site running someone else's branding never points its owner at a
     * third party. Both halves are escaped here; callers echo the result.
     */
    public static function supportLink(string $label): string
    {
        $url = self::getSupportUrl();

        if ($url === '') {
            return esc_html($label);
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url($url),
            esc_html($label)
        );
    }

    /**
     * Get the active author/company URL.
     */
    public static function getAuthorUrl(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['author_url']))
            ? (string) $settings['author_url']
            : self::DEFAULTS['author_url'];
    }

    /**
     * Get support email.
     */
    public static function getSupportEmail(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['support_email']))
            ? (string) $settings['support_email']
            : (string) self::DEFAULTS['support_email'];
    }

    /**
     * Email domains whose users may see the admin menu, normalised to
     * lowercase "@domain.tld" form.
     *
     * Resolution order, first non-empty wins: the
     * `clockwork_companion_agency_email_domains` filter, the
     * CLOCKWORK_AGENCY_EMAIL_DOMAINS constant (for wp-config deployment
     * across a fleet), then the saved branding setting.
     *
     * Deliberately independent of the `enabled` flag: menu gating is an
     * access-control decision, not a cosmetic one, so it must work whether or
     * not white labeling is switched on.
     *
     * An empty result means "no gating" — see Menu::currentUserIsAgency().
     *
     * @return array<int, string>
     */
    public static function getAgencyEmailDomains(): array
    {
        $raw = '';

        if (defined('CLOCKWORK_AGENCY_EMAIL_DOMAINS')) {
            $raw = (string) constant('CLOCKWORK_AGENCY_EMAIL_DOMAINS');
        }

        $settings = self::getSettings();

        if ($raw === '') {
            $raw = (string) ($settings['agency_email_domains'] ?? '');
        }

        $domains = self::parseAgencyEmailDomains($raw);

        // With no explicit list, a white-labelled install that set its own
        // support address has still declared who "the agency" is, so gate on
        // that domain — this is what makes hide_plugin_row work for an
        // operator who only filled in the branding fields. The stock support
        // address is deliberately excluded: on a fresh install it would gate
        // the menu to a domain nobody on the site has, hiding the plugin from
        // the administrator who just installed it.
        if (
            $domains === []
            && ! empty($settings['enabled'])
            && ! empty($settings['support_email'])
            && $settings['support_email'] !== self::DEFAULTS['support_email']
        ) {
            $domains = self::parseAgencyEmailDomains((string) $settings['support_email']);
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('clockwork_companion_agency_email_domains', $domains);
            if (is_array($filtered)) {
                $domains = self::parseAgencyEmailDomains(implode(',', $filtered));
            }
        }

        return $domains;
    }

    /**
     * Split a comma/newline/space-separated domain list into normalised
     * "@domain.tld" entries. Accepts entries with or without the leading "@",
     * and tolerates a full email address by keeping only its domain part.
     *
     * @return array<int, string>
     */
    private static function parseAgencyEmailDomains(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [];
        $domains = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // "ops@agency.com" and "agency.com" both mean "@agency.com".
            if (str_contains($part, '@')) {
                $part = substr(strrchr($part, '@'), 1);
            }
            $part = ltrim($part, '@');
            if ($part === '' || ! str_contains($part, '.')) {
                continue;
            }
            $domain = '@' . $part;
            if (! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Get the active menu title.
     */
    public static function getMenuTitle(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['menu_title']))
            ? (string) $settings['menu_title']
            : self::DEFAULTS['menu_title'];
    }

    /**
     * Get the active menu icon (Dashicon or image URL).
     */
    public static function getMenuIcon(): string
    {
        $settings = self::getSettings();
        if (! empty($settings['enabled']) && ! empty($settings['menu_icon'])) {
            return (string) $settings['menu_icon'];
        }
        if (! empty($settings['enabled']) && ! empty($settings['menu_icon_url'])) {
            return (string) $settings['menu_icon_url'];
        }

        return '';
    }

    /**
     * Get the active brand header text.
     */
    public static function getBrandText(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['brand_text']))
            ? (string) $settings['brand_text']
            : self::DEFAULTS['brand_text'];
    }

    /**
     * Get the active logo URL (falling back to default bundle logo).
     */
    public static function getLogoUrl(): string
    {
        $settings = self::getSettings();
        if (! empty($settings['enabled']) && ! empty($settings['logo_url'])) {
            return (string) $settings['logo_url'];
        }

        if (defined('CLOCKWORK_COMPANION_DIR')) {
            return plugins_url('assets/clockwork-logo.png', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php');
        }

        return '';
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
     * Whether help/doc links are hidden.
     */
    public static function areHelpLinksHidden(): bool
    {
        $settings = self::getSettings();

        return ! empty($settings['enabled']) && ! empty($settings['hide_help_links']);
    }

    /**
     * Whether the companion plugin row should be hidden on plugins.php for non-agency admins.
     */
    public static function isPluginRowHidden(): bool
    {
        $settings = self::getSettings();

        return ! empty($settings['enabled']) && ! empty($settings['hide_plugin_row']);
    }

    /**
     * Custom footer text if configured.
     */
    public static function getFooterText(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['footer_text']))
            ? (string) $settings['footer_text']
            : '';
    }

    /**
     * Get the support button label.
     */
    public static function getSupportButtonLabel(): string
    {
        $settings = self::getSettings();

        return (! empty($settings['enabled']) && ! empty($settings['support_button_label']))
            ? (string) $settings['support_button_label']
            : self::DEFAULTS['support_button_label'];
    }

    /**
     * Get the custom support URL, if specified or mailto.
     */
    public static function getSupportUrl(): string
    {
        $settings = self::getSettings();
        if (! empty($settings['enabled']) && ! empty($settings['support_url'])) {
            return (string) $settings['support_url'];
        }

        if (! empty($settings['enabled']) && ! empty($settings['support_email'])) {
            return 'mailto:' . (string) $settings['support_email'];
        }

        return '';
    }

    /**
     * Check if the donation prompt should be shown.
     */
    public static function isDonationPromptVisible(): bool
    {
        // Suppress donation prompt entirely if remotely configured or white-labeled
        if (self::isEnabled()) {
            return false;
        }

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
        if (! self::isEnabled()) {
            return $plugins;
        }

        $settings = self::getSettings();
        $isAgency = Menu::currentUserIsAgency();

        foreach ($plugins as $file => &$data) {
            if ($this->isCompanionPluginFile($file)) {
                // If hide_plugin_row is active and current user is not agency, hide row entirely
                if (! empty($settings['hide_plugin_row']) && ! $isAgency) {
                    unset($plugins[$file]);
                    continue;
                }

                if (! empty($settings['plugin_name'])) {
                    $data['Name'] = (string) $settings['plugin_name'];
                    $data['Title'] = (string) $settings['plugin_name'];
                }
                if (! empty($settings['plugin_description'])) {
                    $data['Description'] = (string) $settings['plugin_description'];
                }
                if (! empty($settings['author_name'])) {
                    $data['Author'] = (string) $settings['author_name'];
                    $data['AuthorName'] = (string) $settings['author_name'];
                }
                if (! empty($settings['author_url'])) {
                    $data['AuthorURI'] = (string) $settings['author_url'];
                }
                if (! empty($settings['plugin_url'])) {
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
        if ($type !== 'mustuse' || ! self::isEnabled()) {
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
        if (! self::isEnabled() || ! $this->isCompanionPluginFile($file)) {
            return $meta;
        }

        $settings = self::getSettings();

        // If hide_help_links is active, strip non-author links
        if (! empty($settings['hide_help_links'])) {
            $updated = [];
            foreach ($meta as $item) {
                if (stripos($item, 'By ') !== false || stripos($item, 'author') !== false) {
                    $updated[] = $item;
                }
            }
            $meta = $updated;
        }

        if (empty($settings['author_name'])) {
            return $meta;
        }

        $authorName = esc_html((string) $settings['author_name']);
        $authorUrl = ! empty($settings['author_url']) ? esc_url((string) $settings['author_url']) : '';

        $updated = [];
        foreach ($meta as $item) {
            if (stripos($item, 'By ') !== false || stripos($item, 'author') !== false) {
                if (! empty($authorUrl)) {
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
     * Filter admin footer text for white-label credit.
     */
    public function filterAdminFooterText(string $footerText): string
    {
        $custom = self::getFooterText();
        if ($custom !== '') {
            return esc_html($custom);
        }

        return $footerText;
    }

    /**
     * Check if a plugin file path points to the Clockwork Companion.
     */
    public function isCompanionPluginFile(string $file): bool
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

        $isClockworkPage = str_contains((string) $hook, Menu::SLUG) || str_starts_with((string) $page, 'clockwork');

        $settings = self::getSettings();
        $primary = $this->sanitizeHex($settings['primary_color'] ?? '#6953C4');
        $primaryDark = $this->sanitizeHex($settings['primary_dark_color'] ?? '#2D2062');
        $primarySoft = $this->sanitizeHex($settings['primary_soft_color'] ?? '#D1C9F4');
        $accent = $this->sanitizeHex($settings['accent_color'] ?? '#7EFF83');
        $pageBg = $this->sanitizeHex($settings['page_bg_color'] ?? '#FFFFFF');
        $cardBg = $this->sanitizeHex($settings['card_bg_color'] ?? '#FFFFFF');

        $menuIcon = self::getMenuIcon();

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

        if (! empty($menuIcon) && filter_var($menuIcon, FILTER_VALIDATE_URL)) {
            $escIcon = esc_url($menuIcon);
            echo "#adminmenu #toplevel_page_clockwork .wp-menu-image img {\n";
            echo "    content: url('{$escIcon}') !important;\n";
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
     * Handle POST form submission to save white label settings (legacy fallback).
     */
    public function handleSaveSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_save', 'clockwork_whitelabel_nonce');

        $input = $_POST['whitelabel'] ?? [];
        if (! is_array($input)) {
            $input = [];
        }

        $clean = [
            'enabled' => ! empty($input['enabled']),
            'plugin_name' => sanitize_text_field($input['plugin_name'] ?? self::DEFAULTS['plugin_name']),
            'plugin_description' => sanitize_textarea_field($input['plugin_description'] ?? self::DEFAULTS['plugin_description']),
            'author_name' => sanitize_text_field($input['author_name'] ?? self::DEFAULTS['author_name']),
            'author_url' => esc_url_raw($input['author_url'] ?? ''),
            'plugin_url' => esc_url_raw($input['plugin_url'] ?? ''),
            'menu_title' => sanitize_text_field($input['menu_title'] ?? self::DEFAULTS['menu_title']),
            'brand_text' => sanitize_text_field($input['brand_text'] ?? self::DEFAULTS['brand_text']),
            'logo_url' => esc_url_raw($input['logo_url'] ?? ''),
            'menu_icon_url' => esc_url_raw($input['menu_icon_url'] ?? ''),
            'hide_version' => ! empty($input['hide_version']),
            'hide_plugin_row' => ! empty($input['hide_plugin_row']),
            'hide_help_links' => ! empty($input['hide_help_links']),
            'footer_text' => sanitize_text_field($input['footer_text'] ?? ''),
            'primary_color' => $this->sanitizeHex($input['primary_color'] ?? '#6953C4'),
            'primary_dark_color' => $this->sanitizeHex($input['primary_dark_color'] ?? '#2D2062'),
            'primary_soft_color' => $this->sanitizeHex($input['primary_soft_color'] ?? '#D1C9F4'),
            'accent_color' => $this->sanitizeHex($input['accent_color'] ?? '#7EFF83'),
            'page_bg_color' => $this->sanitizeHex($input['page_bg_color'] ?? '#FFFFFF'),
            'card_bg_color' => $this->sanitizeHex($input['card_bg_color'] ?? '#FFFFFF'),
            'support_button_label' => sanitize_text_field($input['support_button_label'] ?? 'Get Support'),
            'support_url' => esc_url_raw($input['support_url'] ?? ''),
            'agency_email_domains' => implode(
                ', ',
                self::parseAgencyEmailDomains(sanitize_text_field($input['agency_email_domains'] ?? ''))
            ),
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
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_donated');
        update_option(self::DONATED_AT_OPTION, time());

        wp_safe_redirect(add_query_arg(['page' => 'clockwork-branding', 'donated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle "Remind Me Later" trigger.
     */
    public function handleDismissDonationAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('clockwork_whitelabel_dismiss');
        update_option(self::DISMISSED_AT_OPTION, time());

        wp_safe_redirect(add_query_arg(['page' => 'clockwork-branding', 'dismissed' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * AJAX handler for "I Donated".
     */
    public function handleAjaxDonated(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('clockwork_whitelabel_donated', 'nonce');
        update_option(self::DONATED_AT_OPTION, time());

        wp_send_json_success(['donated' => true, 'donated_at' => time()]);
    }

    /**
     * AJAX handler for "Remind Me Later".
     */
    public function handleAjaxDismissDonation(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer('clockwork_whitelabel_dismiss', 'nonce');
        update_option(self::DISMISSED_AT_OPTION, time());

        wp_send_json_success(['dismissed' => true, 'dismissed_at' => time()]);
    }
}
