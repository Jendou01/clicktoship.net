<?php

namespace App\Modules\Carriers\Domain;

interface CarrierTransport
{
    public function send(string $operation, array $request, string $correlation): array;
}
