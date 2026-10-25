<?php

namespace App\Modules\Commerce\Domain;

final class IntegrationFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly int $retryAfter = 30)
    {
        parent::__construct($reason);
    }
}
