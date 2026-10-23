<?php

namespace App\Modules\Carriers\Jobs;

use App\Modules\Carriers\Application\CarrierTrackingInbox;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reserved for a future verified authenticator; never dispatched by the disabled external receiver. */
final class RetainCarrierEvent implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $account, public string $merchant, public string $operation, public string $eventKey, public string $rawCode, public string $occurredAt, public array $payload)
    {
        $this->onQueue('carrier-events');
    }

    public function handle(CarrierTrackingInbox $inbox): void
    {
        app(MerchantContext::class)->clear();
        try {
            $inbox->retain($this->account, $this->merchant, $this->operation, $this->eventKey, $this->rawCode, $this->occurredAt, $this->payload);
        } finally {
            app(MerchantContext::class)->clear();
        }
    }
}
