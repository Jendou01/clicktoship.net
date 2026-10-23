<?php

namespace App\Modules\WalletBilling\Domain;

interface PaymentProvider
{
    public function enabled(): bool;

    public function initiate(string $merchant, string $wallet, Money $money, string $reference): array;

    public function event(string $intent, string $eventKey, string $outcome): array;
}
