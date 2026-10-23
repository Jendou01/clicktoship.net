<?php

namespace App\Modules\Carriers\Domain;

interface CarrierAdapterInterface
{
    public function carrier(): string;

    public function request(string $operation, array $input, array $service): array;

    public function normalize(string $operation, string $protocol, array $response): array;
}
