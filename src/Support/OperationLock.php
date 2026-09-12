<?php

namespace ClockworkCompanion\Support;

/**
 * Short-lived per-site lock for expensive HMAC-authed work (malware scan,
 * code snippets). Auth already passed; this only stops overlapping runs
 * from stacking CPU/IO on the origin.
 */
class OperationLock
{
    public static function acquire(string $name, int $ttlSeconds): bool
    {
        $key = self::key($name);
        if (get_transient($key)) {
            return false;
        }

        set_transient($key, 1, max(1, $ttlSeconds));

        return true;
    }

    public static function release(string $name): void
    {
        delete_transient(self::key($name));
    }

    private static function key(string $name): string
    {
        return 'clockwork_lock_'.preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    }
}
