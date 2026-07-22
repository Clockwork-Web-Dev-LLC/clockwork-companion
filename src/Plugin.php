<?php

namespace ClockworkCompanion;

use ClockworkCompanion\ActionLog\Schema as ActionLogSchema;
use ClockworkCompanion\Admin\Actions\RunSecurityScanAction;
use ClockworkCompanion\Admin\FormsAjaxHandlers;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Admin\Support\SupportForm;
use ClockworkCompanion\Admin\UpdatesRefreshAjaxHandler;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\AuthAudit\Schema as AuthAuditSchema;
use ClockworkCompanion\Rest\ActionLogAppendRoute;
use ClockworkCompanion\Rest\AdminsRoute;
use ClockworkCompanion\Rest\BackupsReportRoute;
use ClockworkCompanion\Rest\CommentsSummaryRoute;
use ClockworkCompanion\Rest\CronRoute;
use ClockworkCompanion\Rest\DetectRoute;
use ClockworkCompanion\Rest\FormSubscriptionsRoute;
use ClockworkCompanion\Rest\PostUpdateVerifyRoute;
use ClockworkCompanion\Rest\HealthRoute;
use ClockworkCompanion\Rest\LockoutsRoute;
use ClockworkCompanion\Rest\MalwareScanRoute;
use ClockworkCompanion\Rest\CoreUpdateRoute;
use ClockworkCompanion\Rest\PluginsRoute;
use ClockworkCompanion\Rest\PluginUpdateRoute;
use ClockworkCompanion\Rest\ThemesRoute;
use ClockworkCompanion\Rest\ThemeUpdateRoute;
use ClockworkCompanion\Rest\ResourceReportRoute;
use ClockworkCompanion\Rest\ResourceSamplerConfigRoute;
use ClockworkCompanion\Rest\SecretRotateRoute;
use ClockworkCompanion\Rest\SnapshotRoute;
use ClockworkCompanion\Rest\SsoRoute;
use ClockworkCompanion\Rest\TestContactFormRoute;
use ClockworkCompanion\Rest\TrafficReportRoute;
use ClockworkCompanion\Rest\WordfenceBlocksRoute;
use ClockworkCompanion\Resource\Sampler as ResourceSampler;
use ClockworkCompanion\Resource\Schema as ResourceSchema;
use ClockworkCompanion\Sso\Interceptor as SsoInterceptor;
use ClockworkCompanion\TwoFactor\LoginInterceptor as TwoFactorLoginInterceptor;

class Plugin
{
    public const CAPABILITIES = [
        'contact-form-test',
        'lockouts',
        'wordfence-blocks',
        'plugins',
        'admins',
        'wp-cron',
        'comments-summary',
        'snapshot',
        'backups-report',
        'admin-ui',
        'sso',
        'updates',
        'action-log',
        // Companion can run security scans locally (Sucuri SiteCheck via the
        // public API; core checksums via wp-cli or pure-PHP file hashing).
        // Clockwork's scheduled scans still drive the recurring deliverable;
        // this advertises that the wp-admin Security page also has functional
        // Run buttons.
        'security-scans',
        // In-WP malware probe (PHP-in-uploads, obfuscated-eval signatures,
        // recently-touched wp-config). Bypasses Cloudflare so Clockwork can
        // get real signal on CF-fronted sites where Sucuri SiteCheck 403s.
        'malware-scan',
        // Secret rotation endpoint (1.14.3+).
        'secret-rotate',
        // Auth-failure audit log surfaced on Tools → Clockwork → Security (1.14.4+).
        'auth-audit',
        // Daily 30-day traffic report surfaced on Tools → Clockwork → Traffic
        // (1.16.0+). Push-only; agency rolls up nginx access logs and ships
        // a digest each night.
        'traffic-report',
        // Per-request CPU + memory sampler with hourly rollups (1.17.0+).
        // Clockwork pulls /resource-report every 15 min to feed the per-site
        // CPU leaderboard on /capacity.
        'resource-sampler',
        // Remote toggle for the sampler (1.17.1+). Clockwork's Pause button
        // POSTs to /resource-sampler-config to flip a wp_option, after which
        // Sampler::register() short-circuits — zero per-request overhead.
        'resource-sampler-toggle',
        // Client-self-service form-test subscriptions (1.19.0+). Local admin
        // picks forms to monitor in the wp-admin Forms tab; Clockwork pulls
        // the list daily via /form-subscriptions and reconciles into the
        // agency-side contact_form_tests table.
        'form-subscriptions',
        // Unlock LLAR lockouts via DELETE /lockouts (1.20.0+).
        // Clockwork can clear all lockouts or a specific IP without WP admin access.
        'lockouts-unlock',
        // Post-update state verification (1.21.3+). After every successful
        // update Clockwork calls /post-update-verify with the pre-update
        // active-plugin list + active theme. Companion re-activates any plugin
        // that went inactive and restores the theme if it changed, then
        // returns a repairs list that Clockwork persists to plugin_update_jobs.
        'post-update-verify',
    ];

    public function boot(): void
    {
        Secret::ensure();
        ActionLogSchema::ensureInstalled();
        AuthAuditSchema::ensureInstalled();
        ResourceSchema::ensureInstalled();

        // Third-party plugin compatibility shims — run before route registration
        // so any filters they install are live by the time WordPress's REST
        // auth layer runs. Each shim is unconditional + no-ops cleanly on
        // sites that don't have the targeted plugin active.
        \ClockworkCompanion\Compat\PerfmattersCompat::register();

        // Register the per-request CPU/memory sampler IMMEDIATELY (not on a
        // hook). The sampler snapshots getrusage() at construction time and
        // hooks shutdown internally, so the earlier this runs, the more of
        // the request lifecycle it captures.
        (new ResourceSampler())->register();

        add_action('rest_api_init', function (): void {
            (new HealthRoute())->register();
            (new DetectRoute())->register();
            (new TestContactFormRoute())->register();
            (new LockoutsRoute())->register();
            (new WordfenceBlocksRoute())->register();
            (new PluginsRoute())->register();
            (new ThemesRoute())->register();
            (new PluginUpdateRoute())->register();
            (new ThemeUpdateRoute())->register();
            (new CoreUpdateRoute())->register();
            (new AdminsRoute())->register();
            (new CronRoute())->register();
            (new CommentsSummaryRoute())->register();
            (new SnapshotRoute())->register();
            (new BackupsReportRoute())->register();
            (new SsoRoute())->register();
            (new ActionLogAppendRoute())->register();
            (new SecretRotateRoute())->register();
            (new MalwareScanRoute())->register();
            (new TrafficReportRoute())->register();
            (new ResourceReportRoute())->register();
            (new ResourceSamplerConfigRoute())->register();
            (new FormSubscriptionsRoute())->register();
            (new PostUpdateVerifyRoute())->register();
        });

        // Self-service Forms tab AJAX. Capability + nonce gated; distinct
        // from the HMAC-protected REST routes above (those are for
        // Clockwork; these are for the local wp-admin user).
        (new FormsAjaxHandlers())->register();

        // Loopback admin-ajax handler that refreshes the update_plugins /
        // update_themes transients in a real admin context. Triggered by
        // PluginsRoute / ThemesRoute via wp_remote_post() to make premium
        // plugins (Crocoblock, Elementor Pro, etc.) inject their licensed
        // updates — they gate that injection on admin-context which REST
        // requests don't have. HMAC-signed; not externally invokable.
        (new UpdatesRefreshAjaxHandler())->register();

        // Admin UI — only registers its hooks if we're in wp-admin context.
        // Cheap to call on every request because Menu::register() just adds hooks.
        (new Menu())->register();

        // Support form modal + dashboard widget + AJAX proxy.
        (new SupportForm())->register();

        // admin-post.php handler for the Security page's "Run scan now" buttons.
        // Nonce + manage_options gated; unrelated to the HMAC REST routes above.
        (new RunSecurityScanAction())->register();

        // admin-post.php handler for the Login Security page (2FA enroll /
        // confirm / disable / migrate). Current-user-only operations.
        (new \ClockworkCompanion\Admin\Actions\TwoFactorActions())->register();

        // SSO interceptor — runs on every front-end request to check for the
        // ?clockwork_sso=<nonce> query param. Bound to `init` priority 1
        // inside register(), so output buffering is still safe.
        (new SsoInterceptor())->register();

        // 2FA login gate — sits at the end of the `authenticate` chain and
        // withholds the auth cookie until a TOTP/backup code verifies.
        // No-op for users without 2FA enabled; rescue hatch via the
        // CLOCKWORK_2FA_DISABLE constant.
        (new TwoFactorLoginInterceptor())->register();
    }
}
