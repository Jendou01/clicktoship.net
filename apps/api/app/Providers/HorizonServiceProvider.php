<?php

namespace App\Providers;

use Laravel\Horizon\HorizonServiceProvider as BaseProvider;

final class HorizonServiceProvider extends BaseProvider
{
    protected function registerRoutes(): void
    {
        // Intentionally no dashboard or JSON routes until privileged auth exists.
        // Worker bindings and Artisan commands remain available from the parent.
    }
}
