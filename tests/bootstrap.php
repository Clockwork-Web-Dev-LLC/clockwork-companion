<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    if (! is_dir(WP_CONTENT_DIR)) {
        @mkdir(WP_CONTENT_DIR, 0755, true);
    }
}

if (! is_dir(ABSPATH . 'wp-admin/includes')) {
    @mkdir(ABSPATH . 'wp-admin/includes', 0755, true);
}
if (! is_dir(ABSPATH . 'wp-includes')) {
    @mkdir(ABSPATH . 'wp-includes', 0755, true);
}
foreach ([
    'file.php',
    'misc.php',
    'class-wp-upgrader.php',
    'class-language-pack-upgrader.php',
    'class-wp-upgrader-skin.php',
    'class-automatic-upgrader-skin.php',
    'plugin.php',
    'theme.php',
    'class-core-upgrader.php',
    'class-wp-ajax-upgrader-skin.php',
    'update.php',
    'revision.php',
    'plugin-install.php',
] as $stubFile) {
    if (! file_exists(ABSPATH . 'wp-admin/includes/' . $stubFile)) {
        @touch(ABSPATH . 'wp-admin/includes/' . $stubFile);
    }
}
if (! file_exists(ABSPATH . 'wp-includes/update.php')) {
    @touch(ABSPATH . 'wp-includes/update.php');
}


if (! defined('CLOCKWORK_COMPANION_VERSION')) {
    if (preg_match("/define\\('CLOCKWORK_COMPANION_VERSION',\\s*'([^\x27]+)'\\)/", file_get_contents(dirname(__DIR__) . '/clockwork-companion.php'), $matches)) {
        define('CLOCKWORK_COMPANION_VERSION', $matches[1]);
    } else {
        define('CLOCKWORK_COMPANION_VERSION', '1.36.0');
    }
}

if (! defined('CLOCKWORK_RENEGADE_DIR')) {
    define('CLOCKWORK_RENEGADE_DIR', dirname(__DIR__));
}

if (! defined('CLOCKWORK_COMPANION_NAMESPACE')) {
    define('CLOCKWORK_COMPANION_NAMESPACE', 'clockwork-companion/v1');
}

// In-memory options and hooks storage
$GLOBALS['wp_test_options'] = [];
$GLOBALS['wp_test_filters'] = [];
$GLOBALS['wp_test_actions'] = [];
$GLOBALS['wp_test_current_user'] = null;
$GLOBALS['wp_test_current_user_can'] = true;
$GLOBALS['wp_test_menu_pages'] = [];
$GLOBALS['wp_test_submenu_pages'] = [];

if (! class_exists('WP_User')) {
    class WP_User
    {
        public string $user_email = '';

        public int $ID = 0;

        public string $user_login = '';

        public string $display_name = '';

        /** @var array<int, string> */
        public array $roles = [];

        /**
         * Email stays the first argument: Menu::currentUserIsAgency() is the
         * oldest consumer of this stub and every existing test constructs
         * users positionally by email.
         *
         * @param  array<int, string>  $roles
         */
        public function __construct(
            string $email = '',
            int $id = 0,
            string $login = '',
            array $roles = [],
            string $displayName = ''
        ) {
            $this->user_email = $email;
            $this->ID = $id;
            $this->user_login = $login;
            $this->roles = $roles;
            $this->display_name = $displayName !== '' ? $displayName : $login;
        }
    }
}

// In-memory user store for the user-meta / get_userdata stubs below.
// Tests register users with $GLOBALS['wp_test_users'][$id] = new WP_User(...)
// and read/write meta through the normal WP functions.
$GLOBALS['wp_test_users'] = [];
$GLOBALS['wp_test_user_meta'] = [];

if (! function_exists('get_userdata')) {
    function get_userdata(int $userId): WP_User|false
    {
        return $GLOBALS['wp_test_users'][$userId] ?? false;
    }
}

if (! function_exists('user_can')) {
    function user_can(int $userId, string $capability): bool
    {
        $user = $GLOBALS['wp_test_users'][$userId] ?? null;
        if (! $user) {
            return false;
        }

        // Only the distinction the 2FA code actually leans on: administrators
        // have manage_options, editors do not.
        if ($capability === 'manage_options') {
            return in_array('administrator', $user->roles, true);
        }

        return true;
    }
}

if (! function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
    {
        $value = $GLOBALS['wp_test_user_meta'][$userId][$key] ?? '';

        return $single ? $value : [$value];
    }
}

if (! function_exists('update_user_meta')) {
    function update_user_meta(int $userId, string $key, mixed $value): bool
    {
        $GLOBALS['wp_test_user_meta'][$userId][$key] = $value;

        return true;
    }
}

if (! function_exists('delete_user_meta')) {
    function delete_user_meta(int $userId, string $key, mixed $value = ''): bool
    {
        unset($GLOBALS['wp_test_user_meta'][$userId][$key]);

        return true;
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        $user = $GLOBALS['wp_test_current_user'] ?? null;

        return $user ? (int) $user->ID : 0;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['wp_test_options'][$option] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['wp_test_options'][$option]);
        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['wp_test_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['wp_test_transients'][$transient] = $value;
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['wp_test_transients'][$transient]);
        return true;
    }
}



if (! function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool
    {
        unset($GLOBALS['wp_test_transients']['site_' . $transient]);
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wp_test_filters'][$tag][] = $callback;
        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wp_test_actions'][$tag][] = $callback;
        return true;
    }
}

if (! function_exists('apply_filters')) {
    /**
     * Runs callbacks registered through the add_filter() stub above, in
     * registration order, so filter-driven behaviour is actually exercised
     * rather than silently skipped.
     */
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['wp_test_filters'][$tag] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? false : $email;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return ($GLOBALS['wp_test_home_url'] ?? 'https://example.test') . $path;
    }
}

if (! function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.com/wp-content/mu-plugins/' . ltrim($path, '/');
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        // Defaults to true so the many tests that never think about
        // capabilities keep passing; set the global to false to exercise a
        // denial path.
        return $GLOBALS['wp_test_current_user_can'] ?? true;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'test_nonce_' . md5($action);
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): bool
    {
        return $nonce === 'test_nonce_' . md5($action);
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): ?WP_User
    {
        return $GLOBALS['wp_test_current_user'] ?? new WP_User('admin@clientdomain.com');
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(
            protected string $method = 'GET',
            protected string $route = '',
            protected ?array $jsonParams = [],
            protected array $headers = [],
            protected string $body = ''
        ) {}

        public function get_method(): string { return $this->method; }
        public function get_route(): string { return $this->route; }
        public function get_json_params(): ?array { return $this->jsonParams; }
        public function get_header(string $header): ?string {
            $normalized = str_replace('-', '_', strtolower($header));
            foreach ($this->headers as $k => $v) {
                if (str_replace('-', '_', strtolower($k)) === $normalized) {
                    return $v;
                }
            }
            return null;
        }
        public function set_body(string $body): void { $this->body = $body; }
        public function set_param(string $key, mixed $value): void { $this->jsonParams[$key] = $value; }
        public function get_body(): string { return $this->body ?: (!empty($this->jsonParams) ? json_encode($this->jsonParams) : ''); }
        public function get_param(string $key): mixed { return $this->jsonParams[$key] ?? null; }
        public function get_params(): array { return (array) $this->jsonParams; }
    }
}

// WordPress Comments stubs
$GLOBALS['wp_test_comments'] = [];

if (! function_exists('get_comments')) {
    function get_comments(array $args = []): array
    {
        $all = $GLOBALS['wp_test_comments'] ?? [];
        $status = $args['status'] ?? 'all';
        $filtered = [];
        foreach ($all as $c) {
            if ($status === 'all' || ($c->comment_approved ?? '') === $status) {
                $filtered[] = $c;
            }
        }
        $offset = (int) ($args['offset'] ?? 0);
        $number = (int) ($args['number'] ?? 20);
        return array_slice($filtered, $offset, $number);
    }
}

if (! function_exists('wp_count_comments')) {
    function wp_count_comments(): object
    {
        $counts = (object) [
            'approved' => 0,
            'moderated' => 0,
            'spam' => 0,
            'trash' => 0,
            'total_comments' => 0,
        ];
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $c) {
            $counts->total_comments++;
            if (($c->comment_approved ?? '') === '1' || ($c->comment_approved ?? '') === 'approve') {
                $counts->approved++;
            } elseif (($c->comment_approved ?? '') === '0' || ($c->comment_approved ?? '') === 'hold') {
                $counts->moderated++;
            } elseif (($c->comment_approved ?? '') === 'spam') {
                $counts->spam++;
            } elseif (($c->comment_approved ?? '') === 'trash') {
                $counts->trash++;
            }
        }
        return $counts;
    }
}

if (! function_exists('wp_set_comment_status')) {
    function wp_set_comment_status(int $comment_id, string $status): bool
    {
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $c) {
            if ((int) $c->comment_ID === $comment_id) {
                $c->comment_approved = ($status === 'approve') ? '1' : (($status === 'hold') ? '0' : $status);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('wp_trash_comment')) {
    function wp_trash_comment(int $comment_id): bool
    {
        return wp_set_comment_status($comment_id, 'trash');
    }
}

if (! function_exists('wp_spam_comment')) {
    function wp_spam_comment(int $comment_id): bool
    {
        return wp_set_comment_status($comment_id, 'spam');
    }
}

if (! function_exists('wp_delete_comment')) {
    function wp_delete_comment(int $comment_id, bool $force = false): bool
    {
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $k => $c) {
            if ((int) $c->comment_ID === $comment_id) {
                unset($GLOBALS['wp_test_comments'][$k]);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $post_id): string
    {
        return 'Post #' . $post_id;
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int $post_id): string
    {
        return 'https://example.com/?p=' . $post_id;
    }
}

if (! function_exists('esc_sql')) {
    function esc_sql(string $data): string
    {
        return addslashes($data);
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
            public array $headers = []
        ) {}

        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

if (! function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['wp_test_routes'][$namespace . $route] = $args;
        return true;
    }
}

// Autoload Companion classes
spl_autoload_register(function (string $class): void {
    $prefix = 'ClockworkCompanion\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            protected string $code = '',
            protected string $message = '',
            protected mixed $data = null
        ) {}

        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (! function_exists('delete_site_option')) {
    function delete_site_option(string $option): bool
    {
        return delete_option($option);
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (! function_exists('wp_add_privacy_policy_content')) {
    function wp_add_privacy_policy_content(string $plugin_name, string $policy_text): void
    {
        $GLOBALS['wp_test_privacy_content'][$plugin_name] = $policy_text;
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $comments = 'wp_comments';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $commentmeta = 'wp_commentmeta';
        public string $termmeta = 'wp_termmeta';
        public string $terms = 'wp_terms';
        public string $users = 'wp_users';
        public array $queries = [];

        public function query(string $query): int
        {
            $this->queries[] = $query;
            return 1;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'".addslashes((string) $arg)."'", $query, 1);
            }

            return $query;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
        {
            return null;
        }

        public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
        {
            return [];
        }

        public function get_col(?string $query = null, int $x = 0): array
        {
            return [];
        }

        public function db_version(): string
        {
            return '8.0.36';
        }

        public function db_server_info(): string
        {
            return 'MySQL 8.0.36';
        }
    }
}

$GLOBALS['wpdb'] = new wpdb();

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html__($text, $domain);
    }
}

if (! function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr__($text, $domain);
    }
}

if (! function_exists('wp_kses')) {
    function wp_kses(string $content, array $allowed_html): string
    {
        return $content;
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (! function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(int|float $number, int $decimals = 0): string
    {
        return number_format((float) $number, $decimals);
    }
}

if (! function_exists('wp_get_translation_updates')) {
    function wp_get_translation_updates(): array
    {
        return $GLOBALS['wp_test_translation_updates'] ?? [];
    }
}

if (! function_exists('WP_Filesystem')) {
    function WP_Filesystem(): bool
    {
        return true;
    }
}

if (! class_exists('Automatic_Upgrader_Skin')) {
    class Automatic_Upgrader_Skin
    {
        public array $messages = [];
        public function get_upgrade_messages(): array
        {
            return $this->messages;
        }
        public function get_errors(): object
        {
            return new class {
                public function has_errors(): bool { return false; }
                public function get_error_messages(): array { return []; }
            };
        }
    }
}

if (! class_exists('Language_Pack_Upgrader')) {
    class Language_Pack_Upgrader
    {
        public function __construct(public mixed $skin = null) {}

        public function bulk_upgrade(array $language_updates = [], array $args = []): mixed
        {
            if (isset($GLOBALS['wp_test_bulk_upgrade_result'])) {
                return $GLOBALS['wp_test_bulk_upgrade_result'];
            }
            return [true];
        }
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('get_site_transient')) {
    function get_site_transient(string $transient): mixed
    {
        return get_transient($transient);
    }
}

if (! function_exists('get_plugins')) {
    function get_plugins(): array
    {
        return $GLOBALS['wp_test_plugins'] ?? [];
    }
}

if (! function_exists('wp_get_themes')) {
    function wp_get_themes(): array
    {
        return [];
    }
}

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = 'name'): string
    {
        return '6.7';
    }
}

if (! function_exists('wp_get_theme')) {
    function wp_get_theme(): object
    {
        return new class {
            public function get(string $header): string { return '1.0'; }
        };
    }
}

if (! function_exists('get_site_option')) {
    function get_site_option(string $option, mixed $default = false): mixed
    {
        return get_option($option, $default);
    }
}

if (! function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        return 'twentytwentyfive';
    }
}

if (! function_exists('get_template')) {
    function get_template(): string
    {
        return 'twentytwentyfive';
    }
}

if (! function_exists('get_users')) {
    function get_users(): array
    {
        return [];
    }
}

if (! function_exists('_get_cron_array')) {
    function _get_cron_array(): array
    {
        return [];
    }
}

if (! function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool
    {
        return in_array($plugin, $GLOBALS['wp_test_active_plugins'] ?? [], true);
    }
}

if (! defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (! function_exists('wp_delete_post_revision')) {
    function wp_delete_post_revision(int $id): bool
    {
        return true;
    }
}

if (! function_exists('wp_delete_post')) {
    function wp_delete_post(int $id, bool $force = false): bool
    {
        return true;
    }
}

if (! function_exists('activate_plugin')) {
    function activate_plugin(string $slug, string $redirect = '', bool $networkWide = false, bool $silent = false): mixed
    {
        if (isset($GLOBALS['wp_test_activate_plugin_error'])) {
            return $GLOBALS['wp_test_activate_plugin_error'];
        }
        $GLOBALS['wp_test_active_plugins'][] = $slug;
        return null;
    }
}

if (! function_exists('deactivate_plugins')) {
    function deactivate_plugins(string|array $plugins, bool $silent = false, ?bool $networkWide = null): void
    {
        $slugs = (array) $plugins;
        $active = $GLOBALS['wp_test_active_plugins'] ?? [];
        $GLOBALS['wp_test_active_plugins'] = array_values(array_diff($active, $slugs));
    }
}

if (! function_exists('delete_plugins')) {
    function delete_plugins(array $plugins): bool|\WP_Error
    {
        if (isset($GLOBALS['wp_test_delete_plugins_result'])) {
            return $GLOBALS['wp_test_delete_plugins_result'];
        }
        return true;
    }
}

if (! function_exists('plugins_api')) {
    function plugins_api(string $action, array|object $args = []): object
    {
        if (isset($GLOBALS['wp_test_plugins_api_result'])) {
            return $GLOBALS['wp_test_plugins_api_result'];
        }
        return (object) [
            'name'          => 'Classic Editor',
            'slug'          => 'classic-editor',
            'version'       => '1.6.5',
            'download_link' => 'https://downloads.wordpress.org/plugin/classic-editor.1.6.5.zip',
        ];
    }
}

if (! function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $slug): bool
    {
        return ! empty($GLOBALS['wp_test_network_active_plugins'][$slug]);
    }
}

if (! function_exists('get_home_url')) {
    function get_home_url(?int $blog_id = null, string $path = '', ?string $scheme = null): string
    {
        return 'https://example.com' . $path;
    }
}

if (! function_exists('get_site_url')) {
    function get_site_url(?int $blog_id = null, string $path = '', ?string $scheme = null): string
    {
        return 'https://example.com' . $path;
    }
}

if (! function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return true;
    }
}

if (! function_exists('wp_upload_dir')) {
    function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array
    {
        return [
            'path'    => WP_CONTENT_DIR . '/uploads/2026/09',
            'url'     => 'https://example.com/wp-content/uploads/2026/09',
            'subdir'  => '/2026/09',
            'basedir' => WP_CONTENT_DIR . '/uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error'   => false,
        ];
    }
}

if (! class_exists('WP_Ajax_Upgrader_Skin')) {
    class WP_Ajax_Upgrader_Skin
    {
        public function get_errors(): \WP_Error
        {
            return new \WP_Error();
        }
    }
}

if (! class_exists('Plugin_Upgrader')) {
    class Plugin_Upgrader
    {
        public function __construct(public mixed $skin = null) {}

        public function install(string $package, array $args = []): bool|\WP_Error
        {
            if (isset($GLOBALS['wp_test_plugin_upgrader_install_result'])) {
                return $GLOBALS['wp_test_plugin_upgrader_install_result'];
            }
            return true;
        }

        public function plugin_info(): string
        {
            return 'classic-editor/classic-editor.php';
        }
    }
}

if (! function_exists('get_temp_dir')) {
    function get_temp_dir(): string
    {
        return sys_get_temp_dir();
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return substr(bin2hex(random_bytes(8)), 0, $length);
    }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (! function_exists('get_user_meta')) {
    function get_user_meta(int $user_id, string $key = '', bool $single = false): mixed
    {
        return $single ? '' : [];
    }
}

if (! function_exists('update_user_meta')) {
    function update_user_meta(int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): bool|int
    {
        return true;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $val): mixed
    {
        return is_string($val) ? stripslashes($val) : $val;
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|\WP_Error
    {
        return ['response' => ['code' => 200]];
    }
}

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array|\WP_Error
    {
        return ['response' => ['code' => 200]];
    }
}

if (! function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) {
        $status = is_int($title) ? $title : (is_array($args) && isset($args['response']) ? $args['response'] : 500);
        throw new RuntimeException("wp_die [{$status}]: " . (is_scalar($message) ? $message : ''));
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status_code = null, int $options = 0): void
    {
        $status = $status_code ?? 400;
        $message = is_array($data) ? ($data['message'] ?? json_encode($data)) : (string) $data;
        throw new RuntimeException("wp_send_json_error [{$status}]: {$message}");
    }
}

if (! function_exists('check_ajax_referer')) {
    function check_ajax_referer(string|int $action = -1, string|false $query_arg = false, bool $die = true): int|false
    {
        return 1;
    }
}

if (! function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, string $icon_url = '', ?int $position = null): string
    {
        $GLOBALS['wp_test_menu_pages'][$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'callback' => $callback,
        ];
        return $menu_slug;
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, ?int $position = null): string|false
    {
        $GLOBALS['wp_test_submenu_pages'][$parent_slug][$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'callback' => $callback,
        ];
        return $menu_slug;
    }
}

if (! function_exists('remove_submenu_page')) {
    function remove_submenu_page(string $menu_slug, string $submenu_slug): array|false
    {
        if (isset($GLOBALS['wp_test_submenu_pages'][$menu_slug][$submenu_slug])) {
            $item = $GLOBALS['wp_test_submenu_pages'][$menu_slug][$submenu_slug];
            unset($GLOBALS['wp_test_submenu_pages'][$menu_slug][$submenu_slug]);
            return $item;
        }
        return false;
    }
}
