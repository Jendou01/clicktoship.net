<?php

namespace App\Modules\Shipping\Jobs;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Submission;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessShipment implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $merchant, public readonly string $submission)
    {
        $this->onQueue('shipments');
    }

    public function handle(): void
    {
        app(MerchantContext::class)->clear();
        try {
            app(Submission::class)->process($this->merchant, $this->submission);
        } finally {
            app(MerchantContext::class)->clear();
        }
    }
}
