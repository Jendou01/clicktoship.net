<?php

namespace App\Modules\WalletBilling\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public int $minor;

    public function __construct(string $minor, public string $currency = 'SAR')
    {
        if (! preg_match('/^[A-Z]{3}$/D', $currency) || ! preg_match('/^(0|[1-9][0-9]{0,12})$/D', $minor) || strlen($minor) > 13 || (strlen($minor) === 13 && strcmp($minor, '1000000000000') > 0)) {
            throw new InvalidArgumentException('Invalid integer minor amount.');
        }
        $this->minor = (int) $minor;
    }
}
