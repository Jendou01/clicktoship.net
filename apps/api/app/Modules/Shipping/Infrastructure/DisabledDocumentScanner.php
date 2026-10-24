<?php

namespace App\Modules\Shipping\Infrastructure;

use App\Modules\Shipping\Domain\DocumentScanner;

final class DisabledDocumentScanner implements DocumentScanner
{
    public function clean(string $content): bool
    {
        return false;
    }
}
