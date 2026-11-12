<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Temporarily detaches every callback from a set of action/filter hooks.
 *
 * Lab-mode tests submit a synthetic lead. Integrations that listen on a form
 * plugin's "submitted" hooks (entry storage add-ons, CRMs, mailing lists,
 * webhooks) would otherwise receive it as a real lead. Callbacks are put
 * back exactly as they were (priority, accepted args, order) by restore(),
 * which callers run in a finally block.
 */
class HookSilencer
{
    /** @var array<string, mixed> hook => the WP_Hook (or array) that was registered */
    private array $saved = [];

    /**
     * @param  list<string>  $hooks
     */
    public function silence(array $hooks): void
    {
        global $wp_filter;

        foreach ($hooks as $hook) {
            if (isset($wp_filter[$hook]) && ! array_key_exists($hook, $this->saved)) {
                $this->saved[$hook] = $wp_filter[$hook];
                unset($wp_filter[$hook]);
            }
        }
    }

    public function restore(): void
    {
        global $wp_filter;

        foreach ($this->saved as $hook => $registered) {
            // Anything added to the hook while it was silenced is dropped;
            // the original registrations come back unchanged.
            $wp_filter[$hook] = $registered;
        }
        $this->saved = [];
    }

    /**
     * @return list<string>
     */
    public function silencedHooks(): array
    {
        return array_keys($this->saved);
    }
}
