<?php

namespace App\Modules\Shipping\Domain;

interface DocumentScanner
{
    public function clean(string $content): bool;
}
