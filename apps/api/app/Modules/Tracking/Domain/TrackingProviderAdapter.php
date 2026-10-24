<?php

namespace App\Modules\Tracking\Domain;

interface TrackingProviderAdapter
{
    public function provider(): string;

    /** Code-owned evidence, not a client/database activation flag. */
    public function capabilities(): array;

    public function poll(object $binding): array;

    public function normalize(array $event): array;

    public function authenticator(): WebhookAuthenticator;
}
