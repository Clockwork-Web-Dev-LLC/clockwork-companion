<?php

namespace ClockworkCompanion\Auth;

use ClockworkCompanion\ActionLog\Repository as ActionLogRepository;

/**
 * The site's own record of privileged things done to it over the signed
 * REST API (and of SSO links being used).
 *
 * Control mirrors its own action log into the same table, but that copy is
 * only as complete as Control chooses to make it. These rows are written
 * here, by the site, whenever the action happens — so running code, minting
 * a login link or changing plugins always leaves a trace on the site itself.
 * They use their own action types so they never read as duplicates of
 * Control's mirrored rows.
 *
 * Best-effort: a logging failure must never turn into a failed action.
 */
class RemoteActionAudit
{
    public const ACTOR = 'remote';

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $actionType, ?string $target, string $summary, array $details = [], bool $ok = true, ?string $error = null): void
    {
        try {
            ActionLogRepository::insert([
                'action_type' => $actionType,
                'target' => $target,
                'summary' => $summary,
                'details' => $details + ['ip' => HmacVerifier::clientIp()],
                'ok' => $ok,
                'error' => $error,
                'actor' => self::ACTOR,
            ]);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[clockwork-audit] could not record '.$actionType.': '.$e->getMessage());
            }
        }
    }
}
