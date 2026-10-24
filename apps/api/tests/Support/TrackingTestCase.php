<?php

namespace Tests\Support;

use App\Modules\Shipping\Application\Submission;
use App\Modules\Tracking\Application\TrackingService;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

abstract class TrackingTestCase extends ShippingTestCase
{
    protected function trackingFixture(): array
    {
        Queue::fake();
        $f = $this->setupShipment();
        $s = $this->submit($f);
        app(Submission::class)->process($f['m'], $s['submission_id']);
        app(TrackingService::class)->bind($f['m'], DB::table('shipments')->where('id', $f['s']['id'])->value('carrier_observation_id'));
        $f['binding'] = app(TrackingService::class)->binding($f['m'], $f['s']['id']);
        $f['adapter'] = new SyntheticTrackingAdapter;
        app()->instance(TrackingProviderAdapter::class, $f['adapter']);

        return $f;
    }

    protected function refreshTracking(array $f, string $key = 'synthetic-tracking-command')
    {
        return $this->bearer($f['token'], 'POST', '/api/v1/tracking/refresh', ['shipment_ids' => [$f['s']['id']], 'idempotency_key' => $key]);
    }

    protected function event(string $key = 'event-1', string $code = 'TEST_TRANSIT', int $minutes = 5): array
    {
        return ['key' => $key, 'code' => $code, 'at' => now()->subMinutes($minutes)->toIso8601String(), 'location' => 'Synthetic location'];
    }

    protected function endpoint(array $f): string
    {
        $id = (string) Str::uuid();
        DB::table('tracking_endpoints')->insert(['id' => $id, 'merchant_id' => $f['m'], 'account_id' => $f['binding']->account_id, 'enabled' => true]);

        return $id;
    }

    protected function envelope(array $f, array $events = [], array $extra = []): array
    {
        return array_replace(['delivery_id' => (string) Str::uuid(), 'replay_id' => (string) Str::uuid(), 'sent_at' => now()->toIso8601String(), 'reference' => Crypt::decryptString($f['binding']->reference_ciphertext), 'events' => $events ?: [$this->event()]], $extra);
    }

    protected function inbound(string $endpoint, array|string $body, bool $signed = true, string $type = 'application/json')
    {
        $this->resetHttpState();
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/tracking/webhooks/'.$endpoint, [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip, 'HTTP_X_SYNTHETIC_SIGNATURE' => $signed ? SyntheticTrackingAdapter::sign($raw) : 'forged'], $raw);
    }
}
