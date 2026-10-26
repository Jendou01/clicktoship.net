<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\DB;

final class ConfigurationAudit
{
    /** Configuration-only preflight; never activates, deploys or prints secret values. */
    public function checks(): array
    {
        $url = parse_url((string) config('app.url'));
        $hosts = config('security.allowed_hosts', []);
        $origin = ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');
        $out = [
            'NON_DEVELOPMENT_ENVIRONMENT' => ! app()->environment('local', 'testing'),
            'DEBUG_DISABLED' => config('app.debug') === false,
            'HTTPS_APPLICATION_ORIGIN' => ($url['scheme'] ?? '') === 'https' && ! isset($url['user']) && ! isset($url['pass']) && ! in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true),
            'EXACT_ALLOWED_HOSTS' => $hosts && count(array_filter($hosts, fn ($h) => (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/Di', $h))) === count($hosts) && in_array(strtolower($url['host'] ?? ''), array_map('strtolower', $hosts), true),
            'SECURE_HTTPONLY_COOKIES' => config('session.secure') === true && config('session.http_only') === true,
            'SAME_SITE_AND_HOST_ONLY_COOKIE' => in_array(config('session.same_site'), ['lax', 'strict'], true) && ! config('session.domain'),
            'REDIS_ENCRYPTED_SESSIONS' => config('session.driver') === 'redis' && config('session.encrypt') === true,
            'EXACT_STATEFUL_ORIGIN' => $origin && in_array($origin, config('sanctum.stateful'), true) && ! array_filter(config('sanctum.stateful'), fn ($s) => str_contains($s, '*')),
            'PRIVATE_MAIL_TRANSPORT' => in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend'], true),
            'REVIEWED_FAILURE_STORAGE' => in_array(config('queue.failed.driver'), ['database-uuids', 'null', null], true),
            'FINANCIAL_GATE_CLOSED' => config('finance.provider') === 'disabled' && config('finance.corrections_enabled') === false,
            'EXPORT_GATE_CLOSED' => config('analytics.test_artifacts_enabled') === false,
            'NON_STRUCTURAL_ONLY_SCANNING' => config('bulk.structural_only') === false,
            'APPLICATION_ENCRYPTION_KEY_PRESENT' => is_string(config('app.key')) && str_starts_with(config('app.key'), 'base64:') && strlen((string) base64_decode(substr(config('app.key'), 7), true)) === 32,
        ];
        try {
            $role = DB::selectOne('SELECT rolsuper,rolcreatedb,rolcreaterole,rolbypassrls FROM pg_roles WHERE rolname=current_user');
            $out['RESTRICTED_DATABASE_LOGIN'] = $role && ! $role->rolsuper && ! $role->rolcreatedb && ! $role->rolcreaterole && ! $role->rolbypassrls;
        } catch (\Throwable) {
            $out['RESTRICTED_DATABASE_LOGIN'] = false;
        }

        return array_map(fn ($code, $ok) => ['code' => $code, 'ok' => (bool) $ok], array_keys($out), array_values($out));
    }
}
