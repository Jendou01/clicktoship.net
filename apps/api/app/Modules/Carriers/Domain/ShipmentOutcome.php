<?php

namespace App\Modules\Carriers\Domain;

final readonly class ShipmentOutcome
{
    public function __construct(public string $outcome, public string $evidenceHash, public ?string $reference = null)
    {
        if (! in_array($outcome, ['accepted', 'rejected', 'unknown']) || ! preg_match('/^[a-f0-9]{64}$/', $evidenceHash) || ($outcome === 'accepted' && (! $reference || strlen($reference) > 100))) {
            throw new \InvalidArgumentException('Invalid normalized carrier outcome');
        }
    }
}
