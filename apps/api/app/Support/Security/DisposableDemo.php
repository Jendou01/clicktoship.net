<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Http;
use LogicException;

/** A separate opt-in execution policy; never an exception to production preflight. */
final class DisposableDemo
{
    public static function assertConfiguration(): void
    {
        if (! config('demo.enabled') && ! app()->environment('demo')) {
            return;
        }
        $required = [
            config('demo.enabled') === true,
            app()->environment('demo'),
            config('demo.acknowledgment') === 'SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED',
            preg_match('/^cts-demo-[a-f0-9]{12}$/D', (string) config('demo.instance')) === 1,
            config('app.debug') === false,
            config('app.url') === 'https://staging.zeeship.cloud',
            config('security.allowed_hosts') === ['staging.zeeship.cloud'],
            config('security.trusted_proxies') === ['172.30.73.10'],
            config('database.default') === 'pgsql',
            config('database.connections.pgsql.host') === 'postgres',
            config('database.connections.pgsql.database') === 'clicktoship_demo',
            in_array(config('database.connections.pgsql.username'), ['cts_runtime', 'cts_owner'], true),
            ! config('database.connections.pgsql.url'),
            config('database.redis.default.host') === 'redis',
            ! config('database.redis.default.url'),
            config('database.redis.options.prefix') === config('demo.instance').':',
            config('mail.default') === 'smtp',
            config('mail.mailers.smtp.host') === 'mailpit',
            (int) config('mail.mailers.smtp.port') === 1025,
            ! config('mail.mailers.smtp.url'),
            ! config('mail.mailers.smtp.username'),
            ! config('mail.mailers.smtp.password'),
            config('session.driver') === 'redis',
            config('session.encrypt') === true,
            config('session.secure') === true,
            config('session.http_only') === true,
            config('session.same_site') === 'lax',
            ! config('session.domain'),
            config('sanctum.stateful') === ['staging.zeeship.cloud'],
            config('finance.provider') === 'disabled',
            config('finance.corrections_enabled') === false,
            config('analytics.test_artifacts_enabled') === false,
            config('bulk.structural_only') === false,
        ];
        if (in_array(false, $required, true)) {
            // Static code only: no configuration values in exceptions/logs.
            throw new LogicException('DISPOSABLE_DEMO_CONFIGURATION_REJECTED');
        }
        // The internal Docker network is the primary egress boundary. This also
        // rejects stray Laravel HTTP requests; it never supplies fake success.
        Http::preventStrayRequests();
    }
}
