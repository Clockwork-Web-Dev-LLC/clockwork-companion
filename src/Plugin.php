<?php

namespace ClockworkCompanion;

use ClockworkCompanion\Auth\Secret;
use ClockworkCompanion\Rest\DetectRoute;
use ClockworkCompanion\Rest\HealthRoute;
use ClockworkCompanion\Rest\TestContactFormRoute;

class Plugin
{
    public const CAPABILITIES = [
        'contact-form-test',
    ];

    public function boot(): void
    {
        Secret::ensure();

        add_action('rest_api_init', function (): void {
            (new HealthRoute())->register();
            (new DetectRoute())->register();
            (new TestContactFormRoute())->register();
        });
    }
}
