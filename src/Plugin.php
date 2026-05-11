<?php

namespace ClockworkCompanion;

use ClockworkCompanion\ActionLog\Schema as ActionLogSchema;
use ClockworkCompanion\Admin\Actions\RunSecurityScanAction;
use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\AuthAudit\Schema as AuthAuditSchema;
use ClockworkCompanion\Rest\ActionLogAppendRoute;
use ClockworkCompanion\Rest\AdminsRoute;
use ClockworkCompanion\Rest\BackupsReportRoute;
use ClockworkCompanion\Rest\CommentsSummaryRoute;
use ClockworkCompanion\Rest\CronRoute;
use ClockworkCompanion\Rest\DetectRoute;
use ClockworkCompanion\Rest\HealthRoute;
use ClockworkCompanion\Rest\LockoutsRoute;
use ClockworkCompanion\Rest\MalwareScanRoute;
use ClockworkCompanion\Rest\PluginsRoute;
use ClockworkCompanion\Rest\PluginUpdateRoute;
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
    ];

    public function boot(): void
    {
        Secret::ensure();
        ActionLogSchema::ensureInstalled();
        AuthAuditSchema::ensureInstalled();
        ResourceSchema::ensureInstalled();

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
            (new AdminsRoute())->register();
            (new CronRoute())->register();
            (new CommentsSummaryRoute())->register();
            (new SnapshotRoute())->register();
            (new BackupsReportRoute())->register();
            (new SsoRoute())->register();
            (new PluginUpdateRoute())->register();
            (new ActionLogAppendRoute())->register();
            (new SecretRotateRoute())->register();
            (new MalwareScanRoute())->register();
            (new TrafficReportRoute())->register();
            (new ResourceReportRoute())->register();
            (new ResourceSamplerConfigRoute())->register();
        });

        // Admin UI — only registers its hooks if we're in wp-admin context.
        // Cheap to call on every request because Menu::register() just adds hooks.
        (new Menu())->register();

        // admin-post.php handler for the Security page's "Run scan now" buttons.
        // Nonce + manage_options gated; unrelated to the HMAC REST routes above.
        (new RunSecurityScanAction())->register();

        // SSO interceptor — runs on every front-end request to check for the
        // ?clockwork_sso=<nonce> query param. Bound to `init` priority 1
        // inside register(), so output buffering is still safe.
        (new SsoInterceptor())->register();
    }
}
