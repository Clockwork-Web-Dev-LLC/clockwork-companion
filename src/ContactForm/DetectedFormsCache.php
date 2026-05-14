<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Cached snapshot of the detection result from PluginRegistry — the list of
 * forms that the active form plugin reports, plus the plugin slug itself.
 * The Forms admin page renders from this cache so a page-load doesn't have
 * to re-enumerate every time.
 *
 * Refreshed by FormsPage on first load when stale (>24h) and via the manual
 * "Re-detect" button. Clockwork's `/detect` endpoint also runs detection
 * (independently) but stores its result on the agency side; this cache is
 * the local mirror used by the wp-admin UI.
 */
class DetectedFormsCache
{
    public const OPTION = 'clockwork_companion_detected_forms';

    /** Cache lifetime — after this, the next page load refreshes. */
    public const STALE_AFTER_SECONDS = 24 * 60 * 60;

    /**
     * @return array{plugin: ?string, forms: list<array{id:string, title:string, page_url:?string}>, fetched_at: ?string}
     */
    public static function read(): array
    {
        $raw = get_option(self::OPTION, null);
        if (! is_array($raw)) {
            return ['plugin' => null, 'forms' => [], 'fetched_at' => null];
        }
        return [
            'plugin' => isset($raw['plugin']) && is_string($raw['plugin']) ? $raw['plugin'] : null,
            'forms' => isset($raw['forms']) && is_array($raw['forms']) ? array_values($raw['forms']) : [],
            'fetched_at' => isset($raw['fetched_at']) ? (string) $raw['fetched_at'] : null,
        ];
    }

    public static function isStale(): bool
    {
        $cache = self::read();
        if ($cache['fetched_at'] === null) {
            return true;
        }
        $ts = strtotime($cache['fetched_at']);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Run detection now and persist the result. Returns the freshly cached
     * payload.
     *
     * @return array{plugin: ?string, forms: list<array{id:string, title:string, page_url:?string}>, fetched_at: string}
     */
    public static function refresh(): array
    {
        $registry = new PluginRegistry();
        $plugin = $registry->detectActive();
        $forms = $plugin !== null ? $registry->enumerateForms($plugin) : [];

        // Normalize — drop anything we won't render and ensure consistent shape.
        $normalized = [];
        foreach ($forms as $f) {
            if (! is_array($f)) {
                continue;
            }
            $id = isset($f['id']) ? (string) $f['id'] : '';
            if ($id === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'title' => isset($f['title']) ? (string) $f['title'] : '',
                'page_url' => isset($f['page_url']) ? (string) $f['page_url'] : null,
            ];
        }

        $payload = [
            'plugin' => $plugin,
            'forms' => $normalized,
            'fetched_at' => gmdate('c'),
        ];
        update_option(self::OPTION, $payload, false);
        return $payload;
    }

    /**
     * Return the cache, refreshing it transparently if stale.
     *
     * @return array{plugin: ?string, forms: list<array{id:string, title:string, page_url:?string}>, fetched_at: ?string}
     */
    public static function readFresh(): array
    {
        if (self::isStale()) {
            return self::refresh();
        }
        return self::read();
    }
}
