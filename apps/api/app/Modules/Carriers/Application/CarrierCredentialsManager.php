<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\CarrierFailure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CarrierCredentialsManager
{
    public const FIELDS = ['UserName', 'Password', 'Version', 'AccountNumber', 'AccountPin', 'AccountEntity', 'AccountCountryCode', 'Source'];

    public function encrypt(?array $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $rules = [];
        foreach (self::FIELDS as $key) {
            $rules[$key] = 'required|string|max:200';
        }
        Validator::make($input, $rules)->validate();
        abort_if(array_diff(array_keys($input), self::FIELDS) !== [], 422);

        return Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR));
    }

    public function forSandbox(string $account, int $version): array
    {
        $row = DB::table('carrier_accounts as a')->join('carrier_account_versions as v', 'v.account_id', '=', 'a.id')->where('a.id', $account)->where('v.version', $version)->where('a.active_version', $version)->first(['a.environment', 'v.credentials_ciphertext']);
        if (! $row || $row->environment !== 'sandbox' || ! $row->credentials_ciphertext) {
            throw new CarrierFailure('CREDENTIALS_UNAVAILABLE');
        }

        return json_decode(Crypt::decryptString($row->credentials_ciphertext), true, flags: JSON_THROW_ON_ERROR);
    }
}
