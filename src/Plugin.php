<?php

namespace ClockworkCompanion;

use ClockworkCompanion\Admin\Menu;
use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Rest\AdminsRoute;
use ClockworkCompanion\Rest\BackupsReportRoute;
use ClockworkCompanion\Rest\CommentsSummaryRoute;
use ClockworkCompanion\Rest\CronRoute;
use ClockworkCompanion\Rest\DetectRoute;
use ClockworkCompanion\Rest\HealthRoute;
use ClockworkCompanion\Rest\LockoutsRoute;
use ClockworkCompanion\Rest\PluginsRoute;
use ClockworkCompanion\Rest\PluginUpdateRoute;
use ClockworkCompanion\Rest\SnapshotRoute;
use ClockworkCompanion\Rest\SsoRoute;
use ClockworkCompanion\Rest\TestContactFormRoute;
use ClockworkCompanion\Rest\WordfenceBlocksRoute;
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
    ];

    public function boot(): void
    {
        Secret::ensure();

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
        });

        // Admin UI — only registers its hooks if we're in wp-admin context.
        // Cheap to call on every request because Menu::register() just adds hooks.
        (new Menu())->register();

        // SSO interceptor — runs on every front-end request to check for the
        // ?clockwork_sso=<nonce> query param. Bound to `init` priority 1
        // inside register(), so output buffering is still safe.
        (new SsoInterceptor())->register();
    }
}
