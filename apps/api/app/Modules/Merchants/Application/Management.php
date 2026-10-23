<?php

namespace App\Modules\Merchants\Application;

use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class Management
{
    public function __construct(private MerchantContext $context) {}

    public static function unpack(?object $row): ?array
    {
        return $row ? ['id' => $row->id, 'version' => $row->version, ...json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR)] : null;
    }

    public static function countryRules(): array
    {
        return ['required', 'string', 'size:2', Rule::in(explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'))];
    }

    public function profile(Request $r, bool $write = false): array
    {
        $this->context->require($write ? 'profile.manage' : 'profile.view');
        if (! $write) {
            return ['profile' => self::unpack(DB::table('merchant_profiles')->where('merchant_id', $this->context->id())->first())];
        }
        $data = $r->validate([
            'version' => 'required|integer|min:0', 'legal_name' => 'required|string|max:160',
            'country' => self::countryRules(), 'contact_name' => 'required|string|max:120',
            'contact_email' => 'required|email:rfc|max:254', 'contact_phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'registration_number' => 'nullable|string|max:80', 'tax_number' => 'nullable|string|max:80',
            'merchant_id' => 'prohibited', 'verification_status' => 'prohibited', 'status' => 'prohibited',
        ]);

        return DB::transaction(function () use ($data): array {
            $merchant = $this->lock('profile.manage');
            abort_if($merchant->verification_status === 'pending', 409);
            $row = DB::table('merchant_profiles')->where('merchant_id', $merchant->id)->first();
            abort_unless(($row->version ?? 0) === $data['version'], 409);
            $payload = $data;
            unset($payload['version']);
            $id = $row?->id ?? (string) Str::uuid();
            DB::table('merchant_profiles')->updateOrInsert(['merchant_id' => $merchant->id], [
                'id' => $id, 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'version' => ($row->version ?? 0) + 1,
                'created_at' => $row?->created_at ?? now(), 'updated_at' => now(),
            ]);
            DB::table('merchants')->where('id', $merchant->id)->update(['verification_status' => 'unverified', 'version' => $merchant->version + 1, 'updated_at' => now()]);
            Audit::record('merchant.profile_updated', $this->context->actorId(), $merchant->id, $id);

            return $this->profile(request());
        });
    }

    public function lock(string $permission): object
    {
        $row = DB::table('merchants')->where('id', $this->context->id())->lockForUpdate()->first();
        abort_unless($row && $row->status === 'active', 403);
        $this->context->require($permission);

        return $row;
    }

    public function settings(Request $r, bool $write = false): array
    {
        $this->context->require($write ? 'settings.manage' : 'merchant.view');
        if (! $write) {
            $row = DB::table('merchant_settings')->where('merchant_id', $this->context->id())->first();

            return ['settings' => $row ? (array) $row : ['version' => 0, 'locale' => 'en', 'timezone' => 'UTC', 'origin_address_id' => null, 'return_address_id' => null]];
        }
        $data = $r->validate(['version' => 'required|integer|min:0', 'locale' => ['required', Rule::in(['en', 'ar'])], 'timezone' => 'required|timezone:all', 'origin_address_id' => 'nullable|uuid', 'return_address_id' => 'nullable|uuid', 'merchant_id' => 'prohibited']);

        return DB::transaction(function () use ($r, $data): array {
            $m = $this->lock('settings.manage');
            $row = DB::table('merchant_settings')->where('merchant_id', $m->id)->first();
            abort_unless(($row->version ?? 0) === $data['version'], 409);
            foreach (['origin_address_id', 'return_address_id'] as $key) {
                if ($data[$key] ?? null) {
                    abort_unless(DB::table('merchant_addresses')->where('merchant_id', $m->id)->where('id', $data[$key])->whereNull('deleted_at')->exists(), 404);
                }
            }
            DB::table('merchant_settings')->updateOrInsert(['merchant_id' => $m->id], [...$data, 'id' => $row?->id ?? (string) Str::uuid(), 'version' => ($row->version ?? 0) + 1, 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            Audit::record('merchant.settings_updated', $this->context->actorId(), $m->id, $m->id);

            return $this->settings($r);
        });
    }

    public function addresses(Request $r, ?string $id = null, string $action = 'index'): array
    {
        $this->context->require(in_array($action, ['index', 'show']) ? 'addresses.view' : 'addresses.manage');
        if ($action === 'index') {
            $p = $r->validate(['page' => 'sometimes|integer|min:1']);
            $rows = DB::table('merchant_addresses')->where('merchant_id', $this->context->id())->whereNull('deleted_at')->orderBy('created_at')->orderBy('id')->paginate(25);

            return ['addresses' => array_map(self::unpack(...), $rows->items()), 'pagination' => ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]];
        }
        if ($action === 'show') {
            return ['address' => self::unpack($this->address($id))];
        }
        $data = $r->validate($action === 'delete' ? ['version' => 'required|integer|min:1'] : [
            'version' => 'required|integer|min:0', 'label' => 'required|string|max:100', 'contact_name' => 'required|string|max:120',
            'contact_phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'], 'country' => self::countryRules(),
            'city' => 'required|string|max:100', 'region' => 'nullable|string|max:100', 'postal_code' => 'nullable|string|max:20',
            'line1' => 'required|string|max:200', 'line2' => 'nullable|string|max:200', 'merchant_id' => 'prohibited',
        ]);
        // Validate only known country-specific rules; no carrier eligibility claim.
        if ($action !== 'delete') {
            if ($data['country'] === 'SA') {
                $r->validate(['postal_code' => ['required', 'regex:/^[0-9]{5}$/']]);
            } elseif ($data['country'] === 'US') {
                $r->validate(['region' => 'required|string|max:100', 'postal_code' => ['required', 'regex:/^[0-9]{5}(-[0-9]{4})?$/']]);
            }
        }

        return DB::transaction(function () use ($id, $action, $data): array {
            $m = $this->lock('addresses.manage');
            $row = $id ? $this->address($id) : null;
            abort_unless(($row->version ?? 0) === $data['version'], 409);
            if ($action === 'delete') {
                DB::table('merchant_settings')->where('merchant_id', $m->id)->where(fn ($q) => $q->where('origin_address_id', $id)->orWhere('return_address_id', $id))->lockForUpdate()->get();
                abort_if(DB::table('merchant_settings')->where('merchant_id', $m->id)->where(fn ($q) => $q->where('origin_address_id', $id)->orWhere('return_address_id', $id))->exists(), 409);
                DB::table('merchant_addresses')->where('id', $id)->update(['deleted_at' => now(), 'version' => $row->version + 1]);
            } else {
                $payload = $data;
                unset($payload['version']);
                $id ??= (string) Str::uuid();
                DB::table('merchant_addresses')->updateOrInsert(['id' => $id, 'merchant_id' => $m->id], ['payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'version' => ($row->version ?? 0) + 1, 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
            }
            Audit::record('merchant.address_'.$action, $this->context->actorId(), $m->id, $id);

            return $action === 'delete' ? ['archived' => true] : ['address' => self::unpack($this->address($id))];
        });
    }

    private function address(string $id): object
    {
        $row = DB::table('merchant_addresses')->where('merchant_id', $this->context->id())->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($row, 404);

        return $row;
    }

    public function verification(bool $submit = false): array
    {
        $this->context->require($submit ? 'verification.submit' : 'profile.view');
        if ($submit) {
            $data = request()->validate(['version' => 'required|integer|min:1']);
            DB::transaction(function () use ($data): void {
                $m = $this->lock('verification.submit');
                abort_unless($m->version === $data['version'], 409);
                abort_if(in_array($m->verification_status, ['pending', 'verified']), 409);
                $p = DB::table('merchant_profiles')->where('merchant_id', $m->id)->first();
                abort_unless($p, 422);
                $id = (string) Str::uuid();
                DB::table('merchant_verification_submissions')->insert(['id' => $id, 'merchant_id' => $m->id, 'submitted_by' => $this->context->actorId(), 'profile_version' => $p->version, 'snapshot' => $p->payload, 'created_at' => now()]);
                DB::table('merchants')->where('id', $m->id)->update(['verification_status' => 'pending', 'version' => $m->version + 1, 'updated_at' => now()]);
                Audit::record('verification.submitted', $this->context->actorId(), $m->id, $id);
            });
        }
        $rows = DB::table('merchant_verification_submissions as s')->leftJoin('merchant_verification_decisions as d', 'd.submission_id', '=', 's.id')->where('s.merchant_id', $this->context->id())->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(100)->get(['s.id', 's.profile_version', 's.created_at', 's.snapshot', 'd.outcome', 'd.reason', 'd.created_at as reviewed_at']);

        return ['status' => DB::table('merchants')->where('id', $this->context->id())->value('verification_status'), 'submissions' => $rows->map(function ($row) {
            $row->snapshot = json_decode(Crypt::decryptString($row->snapshot), true);
            if ($row->reason) {
                $row->reason = Crypt::decryptString($row->reason);
            }

            return $row;
        })];
    }
}
