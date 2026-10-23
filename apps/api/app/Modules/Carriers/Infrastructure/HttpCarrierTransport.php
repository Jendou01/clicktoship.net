<?php

namespace App\Modules\Carriers\Infrastructure;

use App\Modules\Carriers\Application\CarrierErrorNormalizer;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\CarrierTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/** Hard-coded sandbox host, never user-controlled URLs. Normal concrete implementation is gated. */
abstract class HttpCarrierTransport implements CarrierTransport
{
    abstract protected function authorize(string $operation): void;

    final public function send(string $operation, array $request, string $correlation): array
    {
        $this->authorize($operation);
        if (! isset(CarrierCapabilityRegistry::PATHS[$operation]) || ! Str::isUuid($correlation)) {
            throw new CarrierFailure('UNSUPPORTED_CAPABILITY');
        }
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(15)->withoutRedirecting()->withHeaders(['X-Request-ID' => $correlation])->post('https://ws.sbx.aramex.net/ShippingAPI.V2/'.CarrierCapabilityRegistry::PATHS[$operation], $request);
                if (! $response->successful()) {
                    throw app(CarrierErrorNormalizer::class)->http($operation, $response->status());
                }
                $body = $response->toPsrResponse()->getBody()->read(1048577);
                if (strlen($body) > 1048576) {
                    throw new CarrierFailure('RESPONSE_TOO_LARGE', CarrierCapabilityRegistry::mutating($operation));
                }
                $data = json_decode($body, true);
                if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new CarrierFailure('MALFORMED_RESPONSE', CarrierCapabilityRegistry::mutating($operation));
                }

                return $data;
            } catch (ConnectionException) {
                $error = app(CarrierErrorNormalizer::class)->network($operation);
            } catch (CarrierFailure $e) {
                $error = $e;
            }
            if (! $error->retryable || $error->ambiguous || $attempt === 3) {
                throw $error;
            }
            Sleep::for(random_int(50, 100) * $attempt)->milliseconds();
        }
        throw new CarrierFailure('TRANSPORT_UNAVAILABLE');
    }
}
