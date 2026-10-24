<?php

namespace App\Modules\Tracking\Domain;

interface WebhookAuthenticator
{
    /** Must authenticate raw bytes BEFORE returning authenticated delivery_id, replay_id, sent_at, reference and events. */
    public function verify(string $raw, array $headers): array;
}
