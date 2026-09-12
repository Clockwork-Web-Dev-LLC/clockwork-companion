<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Outcome of a single strategy submission, before MailCapture's findings
 * are folded in. The Tester combines this with MailCapture data to produce
 * the final TestResponse for the REST client.
 */
class StrategyResult
{
    public function __construct(
        public bool $accepted,
        public ?string $error = null,
        public ?string $pluginStatus = null,
        public ?string $logExcerpt = null
    ) {}

    public static function accepted(?string $pluginStatus = null): self
    {
        return new self(true, null, $pluginStatus);
    }

    public static function rejected(string $error, ?string $pluginStatus = null, ?string $logExcerpt = null): self
    {
        return new self(false, $error, $pluginStatus, $logExcerpt);
    }
}
