<?php

namespace App\Modules\WalletBilling\Infrastructure;

use App\Modules\WalletBilling\Domain\Money;
use App\Modules\WalletBilling\Domain\PaymentProvider;
use LogicException;

final class DisabledPaymentProvider implements PaymentProvider
{
    public function enabled(): bool
    {
        return false;
    }

    public function initiate(string $merchant, string $wallet, Money $money, string $reference): array
    {
        throw new LogicException('Real-money funding is unavailable.');
    }

    public function event(string $intent, string $eventKey, string $outcome): array
    {
        throw new LogicException('Payment processing is unavailable.');
    }
}
