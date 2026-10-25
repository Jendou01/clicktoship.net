<?php

namespace Tests\Support;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;
use Illuminate\Support\Facades\DB;

/** INTERNAL ClickToShip fixture protocol. Not a Salla API/signature/response contract. */
final class SyntheticStoreProvider implements StoreProvider
{
    public array $pages = [];

    public int $refreshes = 0;

    public int $fetches = 0;

    public ?\Closure $onRefresh = null;

    public ?array $refreshResult = null;

    public ?\Closure $onExchange = null;

    public ?\Closure $onOrders = null;

    public function __construct()
    {
        abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e'], true), 403);
    }

    public function available(): bool
    {
        return true;
    }

    public function requiredScopes(): array
    {
        return ['synthetic.orders.read'];
    }

    public function authorizationUrl(string $state, string $redirect): string
    {
        return 'https://synthetic.example.test/authorize?state='.$state;
    }

    public function exchange(string $code, string $redirect): array
    {
        if ($this->onExchange) {
            ($this->onExchange)();
        }

        return ['store_id' => $code, 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_at' => now()->addHour()->toIso8601String(), 'scopes' => $this->requiredScopes(), 'provenance' => 'synthetic'];
    }

    public function refresh(array $credential): array
    {
        $this->refreshes++;
        if ($this->onRefresh) {
            ($this->onRefresh)();
        }

        return $this->refreshResult ?? $this->exchange('store-A', '');
    }

    public function orders(array $credential, ?string $checkpoint, ?string $cursor): array
    {
        $this->fetches++;
        if ($this->onOrders) {
            ($this->onOrders)();
        }

        return $this->pages[$cursor ?? 'first'] ?? ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [], 'next_cursor' => null, 'checkpoint' => 'synthetic-checkpoint'];
    }

    public function webhook(string $body, array $headers): array
    {
        $signature = $headers['x-synthetic-signature'][0] ?? '';
        if (! hash_equals(hash_hmac('sha256', $body, 'synthetic-protocol-only'), $signature)) {
            throw new IntegrationFailure('INVALID_SYNTHETIC_SIGNATURE');
        }$v = json_decode($body, true);
        if (! is_array($v) || abs(now()->timestamp - ($v['timestamp'] ?? 0)) > 300) {
            throw new IntegrationFailure('SYNTHETIC_REPLAY_WINDOW');
        }

        return ['store_id' => $v['store'], 'receipt_id' => $v['receipt'], 'kind' => $v['kind'], 'authenticity_verified' => true, 'replay_checked' => true];
    }
}
