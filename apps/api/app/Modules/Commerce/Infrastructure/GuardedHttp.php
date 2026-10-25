<?php

namespace App\Modules\Commerce\Infrastructure;

use App\Modules\Commerce\Domain\IntegrationFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Future verified adapter transport. No endpoint/host is enabled by default. */
final class GuardedHttp
{
    public function request(string $method, string $url, array $headers = [], array $data = []): array
    {
        $p = parse_url($url);
        if (! in_array($method, ['GET', 'POST'], true) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443) || ! in_array($p['host'] ?? '', config('commerce.verified_api_hosts'), true) || filter_var($p['host'] ?? '', FILTER_VALIDATE_IP)) {
            throw new IntegrationFailure('UNVERIFIED_API_HOST');
        }
        try {
            $r = Http::withHeaders($headers)->acceptJson()->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'read_timeout' => 3])->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new IntegrationFailure('API_TIMEOUT', true);
        }
        if ($r->status() === 429) {
            throw new IntegrationFailure('RATE_LIMITED', true, max(1, min(900, (int) $r->header('Retry-After') ?: 30)));
        }
        if ($r->status() === 401) {
            throw new IntegrationFailure('AUTHORIZATION_EXPIRED');
        }if ($r->status() === 403) {
            throw new IntegrationFailure('SCOPE_DENIED');
        }if ($r->serverError()) {
            throw new IntegrationFailure('API_TEMPORARY_FAILURE', true);
        }if (! $r->successful()) {
            throw new IntegrationFailure('API_UNSUPPORTED');
        }
        $stream = $r->toPsrResponse()->getBody();
        $raw = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, config('commerce.max_payload_bytes') + 1 - strlen($raw)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new IntegrationFailure('API_TIMEOUT', true);
                }
                $raw .= $chunk;
                if (strlen($raw) > config('commerce.max_payload_bytes')) {
                    throw new IntegrationFailure('API_PAYLOAD_LIMIT');
                }
            }
        } catch (IntegrationFailure $e) {
            throw $e;
        } catch (\RuntimeException) {
            throw new IntegrationFailure('API_TIMEOUT', true);
        } finally {
            $stream->close();
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new \RuntimeException;
            }

            return $body;
        } catch (\Throwable) {
            throw new IntegrationFailure('API_INVALID_PAYLOAD');
        }
    }
}
