<?php

namespace App\Modules\Tracking\Infrastructure;

use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use App\Modules\Tracking\Domain\WebhookAuthenticator;

final class DisabledTrackingAdapter implements TrackingProviderAdapter, WebhookAuthenticator
{
    public function provider(): string
    {
        return 'aramex';
    }

    public function capabilities(): array
    {
        return ['verified' => false, 'poll' => false, 'webhook' => false, 'synthetic' => false, 'reason' => 'CONTRACT_UNVERIFIED'];
    }

    public function poll(object $binding): array
    {
        abort(503);
    }

    public function normalize(array $event): array
    {
        return ['status' => 'unknown'];
    }

    public function authenticator(): WebhookAuthenticator
    {
        return $this;
    }

    public function verify(string $raw, array $headers): array
    {
        abort(503);
    }
}
