<?php

namespace Tests\Support;

use App\Modules\WalletBilling\Application\Ledger;
use App\Modules\WalletBilling\Domain\Money;
use App\Modules\WalletBilling\Domain\PaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Autoloaded ONLY by Composer autoload-dev; never bound by the application. */
final class SyntheticPaymentProvider implements PaymentProvider
{
    private function guard(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_test') {
            throw new LogicException('Synthetic payments require isolated tests.');
        }
    }

    public function enabled(): bool
    {
        return false;
    }

    public function initiate(string $merchant, string $wallet, Money $money, string $reference): array
    {
        $this->guard();

        return DB::transaction(function () use ($merchant, $wallet, $money, $reference) {
            $existing = DB::table('payment_intents')->where('reference', $reference)->first();
            if ($existing) {
                if ($existing->merchant_id !== $merchant || $existing->wallet_id !== $wallet || (string) $existing->amount !== (string) $money->minor || $existing->currency !== $money->currency) {
                    throw new LogicException('Synthetic intent conflict.');
                }

                return ['id' => $existing->id, 'simulated' => true];
            }
            $id = (string) Str::uuid();
            DB::table('payment_intents')->insert(['id' => $id, 'merchant_id' => $merchant, 'wallet_id' => $wallet, 'currency' => $money->currency, 'amount' => $money->minor, 'provider' => 'synthetic', 'environment' => 'testing', 'reference' => $reference]);

            return ['id' => $id, 'simulated' => true];
        });
    }

    public function event(string $intent, string $eventKey, string $outcome): array
    {
        $this->guard();

        return DB::transaction(function () use ($intent, $eventKey, $outcome) {
            $i = DB::table('payment_intents')->where('id', $intent)->lockForUpdate()->firstOrFail();
            $digest = hash('sha256', json_encode([$intent, $outcome]));
            $old = DB::table('payment_events')->where('provider', 'synthetic')->where('environment', 'testing')->where('event_key', $eventKey)->first();
            if ($old) {
                if ($old->digest !== $digest) {
                    throw new LogicException('Conflicting synthetic event.');
                }

                return ['operation_id' => $old->operation_id, 'simulated' => true];
            }
            $result = $outcome === 'confirmed' ? app(Ledger::class)->command($i->merchant_id, $i->wallet_id, 'synthetic', (string) $i->amount, 'intent:'.$intent, 'event:'.$eventKey, 'synthetic') : [];
            DB::table('payment_events')->insert(['id' => (string) Str::uuid(), 'intent_id' => $intent, 'provider' => 'synthetic', 'environment' => 'testing', 'event_key' => $eventKey, 'digest' => $digest, 'outcome' => $outcome, 'operation_id' => $result['operation_id'] ?? null]);

            return ['operation_id' => $result['operation_id'] ?? null, 'simulated' => true];
        });
    }
}
