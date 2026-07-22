<?php

namespace ClockworkCompanion\Compat;

/**
 * Fix a years-old Elementor race that bakes broken CSS into the host's
 * full-page cache.
 *
 * Elementor's Document::save() deletes a post's compiled CSS file mid-save
 * but only regenerates it lazily, on the next real page load. Hosts purge
 * their page cache earlier in the same save request (SpinupWP hooks
 * transition_post_status, WP Engine hooks save_post), so whichever request
 * lands in that gap — CSS deleted, not yet rebuilt — gets cached as the
 * unstyled page. Confirmed upstream via Elementor GitHub issue #27735; not
 * specific to any one host.
 *
 * This forces the CSS rebuild synchronously, in the save request itself,
 * then re-purges the page cache only once the CSS is safely back on disk.
 *
 * Hook is idempotent and unconditional — `elementor/document/after_save`
 * only ever fires if Elementor is installed and actually saved something,
 * so on non-Elementor sites this simply never runs. The callback body is
 * additionally defensive (class/method existence checks + try/catch)
 * against a future Elementor release changing these internals.
 */
class ElementorCacheGuard
{
    public static function register(): void
    {
        add_action('elementor/document/after_save', static function ($document): void {
            try {
                self::regenerateAndRepurge($document);
            } catch (\Throwable $e) {
                error_log('[clockwork-companion] ElementorCacheGuard: ' . $e->getMessage());
            }
        }, 20, 1);
    }

    private static function regenerateAndRepurge($document): void
    {
        if (! is_object($document) || ! method_exists($document, 'get_main_id')) {
            return;
        }

        $post_id = $document->get_main_id();
        if (! $post_id) {
            return;
        }

        if (! class_exists('\Elementor\Core\Files\CSS\Post')) {
            return;
        }

        // Rebuild now, in this request — not on whoever visits next.
        $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
        if (! is_object($css_file) || ! method_exists($css_file, 'update')) {
            return;
        }
        $css_file->update();

        // Re-purge after the CSS is guaranteed correct on disk.
        if (function_exists('spinupwp')) {
            do_action('spinupwp_purge_url', get_permalink($post_id));
        } elseif (class_exists('\WpeCommon') && method_exists('\WpeCommon', 'purge_varnish_cache')) {
            \WpeCommon::purge_varnish_cache();
        }
    }
}
