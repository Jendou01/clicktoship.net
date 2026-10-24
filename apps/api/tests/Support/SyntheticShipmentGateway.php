<?php

namespace Tests\Support;

use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit synthetic protocol; never claims to implement a real Aramex response. */
final class SyntheticShipmentGateway implements ShipmentGateway
{
    public int $calls = 0;

    public string $outcome = 'accepted';

    public bool $throw = false;

    public function __construct(public string $account)
    {
        $this->guard();
    }

    private function guard(): void
    {
        if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e'])) {
            throw new \LogicException('Synthetic carrier forbidden');
        }
    }

    public function assertReady(string $operation): void
    {
        $this->guard();
    }

    public function prepare(MerchantContext $context, string $business, array $draft, string $operation = 'CreateShipments'): string
    {
        $this->guard();
        $id = (string) Str::uuid();
        DB::table('carrier_operations')->insert(['id' => $id, 'account_id' => $this->account, 'account_version' => 1, 'environment' => 'sandbox', 'merchant_id' => $context->id(), 'actor_id' => $context->actorId(), 'origin_token_id' => $context->tokenId(), 'operation' => $operation, 'business_key' => $business, 'digest' => ShipmentInput::digest($draft), 'request_ciphertext' => Crypt::encryptString(json_encode($draft)), 'correlation_id' => (string) Str::uuid(), 'state' => 'pending']);

        return $id;
    }

    public function send(object $operation): ShipmentOutcome
    {
        $this->guard();
        $this->calls++;
        if ($this->throw) {
            throw new \RuntimeException('Synthetic lost response');
        }

        return new ShipmentOutcome($this->outcome, hash('sha256', 'synthetic:'.$operation->id.':'.$this->outcome), $this->outcome === 'accepted' ? 'SYNTHETIC-'.$operation->id : null);
    }
}
