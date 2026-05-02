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
        public readonly bool $accepted,
        public readonly ?string $error = null,
        public readonly ?string $pluginStatus = null,
        public readonly ?string $logExcerpt = null,
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
