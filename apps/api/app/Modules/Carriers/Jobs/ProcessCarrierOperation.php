<?php

namespace App\Modules\Carriers\Jobs;

use App\Modules\Carriers\Application\CarrierOperations;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCarrierOperation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $merchant, public string $operation)
    {
        $this->onQueue('carrier');
    }

    public function handle(CarrierOperations $operations): void
    {
        app(MerchantContext::class)->clear();
        try {
            $operations->execute($this->merchant, $this->operation);
        } finally {
            app(MerchantContext::class)->clear();
        }
    }
}
