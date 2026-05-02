<?php

namespace ClockworkCompanion\ContactForm\Strategies;

use ClockworkCompanion\ContactForm\StrategyResult;

abstract class AbstractStrategy
{
    /**
     * Submit the named form and return whether the plugin accepted it.
     *
     * Implementations call the form plugin's PHP API directly (not HTTP)
     * so they observe real validation outcomes and can suppress storage
     * via per-plugin filters in lab mode.
     */
    abstract public function submit(string $formId, string $marker, string $mode): StrategyResult;

    /**
     * Standard payload pushed into every form. Each strategy maps these
     * onto its plugin's expected field names.
     *
     * @return array<string, string>
     */
    protected function payload(string $marker): array
    {
        return [
            'name' => 'Clockwork Health Check',
            'email' => 'clockwork-test@clockworkwd.com',
            'subject' => $marker,
            'message' => sprintf(
                "Automated test by Clockwork at %s. Marker: %s.\n\nIf you received this, your contact form is working. You can safely ignore or delete it.",
                gmdate('Y-m-d\TH:i:s\Z'),
                $marker,
            ),
        ];
    }

    /**
     * Hook for the strategy to suppress entry storage in lab mode.
     * Default no-op; CF7 doesn't store by default.
     */
    public function suppressStorage(string $mode): void
    {
        // override in subclass when needed
    }

    public function releaseStorage(): void
    {
        // override in subclass when needed
    }
}
