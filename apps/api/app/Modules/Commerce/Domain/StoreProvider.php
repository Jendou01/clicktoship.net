<?php

namespace App\Modules\Commerce\Domain;

/** ClickToShip normalized boundary. Not an assertion of any provider wire contract. Optional authorization methods do not assert OAuth support. */
interface StoreProvider
{
    public function available(): bool;

    public function requiredScopes(): array;

    public function authorizationUrl(string $state, string $redirect): string;

    public function exchange(string $code, string $redirect): array;

    public function refresh(array $credential): array;

    public function orders(array $credential, ?string $checkpoint, ?string $cursor): array;

    public function webhook(string $body, array $headers): array;
}
