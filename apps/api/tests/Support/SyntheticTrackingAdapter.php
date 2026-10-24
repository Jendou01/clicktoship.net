<?php

namespace Tests\Support;

use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use App\Modules\Tracking\Domain\WebhookAuthenticator;
use Illuminate\Support\Facades\DB;

/** This invented test protocol is NOT an Aramex protocol. Never runtime-autoloaded. */
final class SyntheticTrackingAdapter implements TrackingProviderAdapter, WebhookAuthenticator
{
    public array $events = [];

    public bool $fail = false;

    public $duringPoll = null;

    public function __construct()
    {
        abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
    }

    public function provider(): string
    {
        return 'aramex';
    }

    public function capabilities(): array
    {
        return ['verified' => true, 'poll' => true, 'webhook' => true, 'synthetic' => true];
    }

    public function poll(object $binding): array
    {
        if ($this->duringPoll) {
            ($this->duringPoll)();
        }if ($this->fail) {
            throw new \RuntimeException('Synthetic confidential provider failure');
        }

        return $this->events;
    }

    public function normalize(array $event): array
    {
        $codes = ['TEST_ACCEPTED' => 'carrier_accepted', 'TEST_PICKUP' => 'picked_up', 'TEST_TRANSIT' => 'in_transit', 'TEST_DELIVERED' => 'delivered', 'TEST_RETURNING' => 'returning', 'TEST_RETURNED' => 'returned', 'TEST_EXCEPTION' => 'exception', 'TEST_CANCELLED' => 'cancelled'];

        return ['key' => $event['key'] ?? null, 'code' => $event['code'] ?? null, 'at' => $event['at'] ?? null, 'status' => $codes[$event['code'] ?? ''] ?? 'unknown', 'provenance' => 'isolated synthetic protocol; not Aramex', 'location' => $event['location'] ?? null];
    }

    public function authenticator(): WebhookAuthenticator
    {
        return $this;
    }

    public static function sign(string $raw): string
    {
        return hash_hmac('sha256', $raw, 'isolated-synthetic-test-key');
    }

    public function verify(string $raw, array $headers): array
    {
        abort_unless(hash_equals(self::sign($raw), $headers['x-synthetic-signature'][0] ?? ''), 401);

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }
}
