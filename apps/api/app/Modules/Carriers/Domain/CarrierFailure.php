<?php

namespace App\Modules\Carriers\Domain;

final class CarrierFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $ambiguous = false, public readonly bool $retryable = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($errorCode);
    }
}
