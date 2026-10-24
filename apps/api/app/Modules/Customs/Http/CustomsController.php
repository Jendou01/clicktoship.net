<?php

namespace App\Modules\Customs\Http;

use App\Modules\Customs\Application\Administration;
use App\Modules\Customs\Application\Declarations;
use App\Modules\Customs\Application\DocumentWork;
use App\Modules\Customs\Application\Readiness;
use App\Modules\Customs\Domain\Countries;
use App\Modules\Customs\Domain\CustomsInput;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\ImportEstimates;
use App\Modules\Shipping\Application\Shipments;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CustomsController
{
    public function index(Request $r, MerchantContext $c): mixed
    {
        $c->require('customs.read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('customs_declarations')->where('merchant_id', $c->id())->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'shipment_id', 'version', 'created_at'], 'page', $d['page'] ?? 1);

        return ApiResponse::success(['declarations' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'live_enabled' => false]);
    }

    public function show(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('customs.read');

        $d = $r->validate(['declaration_version' => 'sometimes|integer|min:1']);

        return ApiResponse::success(app(Declarations::class)->show($c->id(), $shipment, $d['declaration_version'] ?? null));
    }

    public function save(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(Declarations::class)->save($r, $c, $shipment));
    }

    public function sync(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(Declarations::class)->sync($r, $c, $shipment));
    }

    public function validate(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return ApiResponse::success(app(Declarations::class)->command($r, $c, 'customs.write', 'validate:'.$shipment, $d, function () use ($r, $c, $shipment, $d) {
            $s = app(Shipments::class)->row($c->id(), $shipment);
            abort_unless($s->version === $d['version'], 409);
            $report = app(Readiness::class)->report(app(Shipments::class)->revision($s));
            DB::table('customs_readiness')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'shipment_id' => $shipment, 'revision' => $s->revision, 'report' => json_encode($report)]);
            app(Declarations::class)->event($c->id(), $shipment, 'readiness_recorded', $r->user()->id);

            return $report;
        }));
    }

    public function price(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('customs.read');
        $c->require('rates.quote');
        $s = app(Shipments::class)->row($c->id(), $shipment);
        $draft = app(Shipments::class)->revision($s);
        try {
            $price = app(ImportEstimates::class)->preview($c->id(), $draft);

            return ApiResponse::success(['state' => 'priced', 'snapshot' => $price, 'duties_calculated' => false, 'accepted' => false]);
        } catch (HttpExceptionInterface|ValidationException|\DomainException) {
            return ApiResponse::success(['state' => 'unavailable', 'reason' => 'VERIFIED_APPLICABLE_PRICING_REQUIRED', 'duties_calculated' => false, 'accepted' => false]);
        }
    }

    public function document(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(DocumentWork::class)->request($r, $c, $shipment), 202);
    }

    public function download(Request $r, MerchantContext $c, string $document): mixed
    {
        return app(DocumentWork::class)->download($r, $c, $document);
    }

    public function revoke(Request $r, MerchantContext $c, string $document): mixed
    {
        return ApiResponse::success(app(Declarations::class)->command($r, $c, 'customs.documents', 'revoke:'.$document, [], function () use ($r, $c, $document) {
            $c->require('shipments.documents');
            $doc = DB::table('customs_documents')->where('merchant_id', $c->id())->where('id', $document)->first();
            abort_unless($doc, 404);
            DB::table('customs_document_revocations')->insertOrIgnore(['document_id' => $document, 'merchant_id' => $c->id(), 'actor_id' => $r->user()->id]);
            app(Declarations::class)->event($c->id(), $doc->shipment_id, 'document_revoked', $r->user()->id);

            return ['revoked' => true];
        }));
    }

    public function profile(Request $r, MerchantContext $c): mixed
    {
        $c->require('customs.read');
        if ($r->isMethod('GET')) {
            $p = DB::table('customs_profiles')->where('merchant_id', $c->id())->orderByDesc('version')->first();

            return ApiResponse::success(['version' => $p->version ?? 0, 'profile' => $p ? json_decode(Crypt::decryptString($p->payload_ciphertext), true) : null]);
        }
        $d = $r->validate(['version' => 'required|integer|min:0', 'profile' => 'required|array:exporter,importer,incoterm,duty_payer,notes', 'profile.exporter' => 'sometimes|array:name,country,city,line1,postal,state,phone,email,business_id,tax_id', 'profile.importer' => 'sometimes|array:name,country,city,line1,postal,state,phone,email,business_id,tax_id', 'profile.exporter.*' => 'nullable|string|max:200', 'profile.importer.*' => 'nullable|string|max:200', 'profile.incoterm' => 'nullable|in:'.implode(',', CustomsInput::TERMS), 'profile.duty_payer' => 'nullable|in:sender,recipient,third_party,unverified', 'profile.notes' => 'nullable|string|max:1000']);

        foreach (['exporter', 'importer'] as $side) {
            $country = $d['profile'][$side]['country'] ?? null;
            if ($country && ! Countries::valid($country)) {
                CustomsInput::fail('profile.'.$side.'.country', 'Use an ISO country code.');
            }
        }

        return ApiResponse::success(app(Declarations::class)->command($r, $c, 'customs.write', 'profile', $d, function () use ($r, $c, $d) {
            $version = (int) DB::table('customs_profiles')->where('merchant_id', $c->id())->max('version');
            abort_unless($version === $d['version'], 409);
            DB::table('customs_profiles')->insert(['merchant_id' => $c->id(), 'version' => $version + 1, 'payload_ciphertext' => Crypt::encryptString(json_encode($d['profile'])), 'actor_id' => $r->user()->id]);

            return ['version' => $version + 1];
        }));
    }

    public function country(Request $r, MerchantContext $c, string $country): mixed
    {
        $c->require('customs.read');

        return ApiResponse::success(app(Readiness::class)->country($country));
    }

    public function rules(Request $r): mixed
    {
        return ApiResponse::success($r->isMethod('GET') ? app(Administration::class)->rules($r) : app(Administration::class)->propose($r));
    }

    public function approve(Request $r, string $country): mixed
    {
        return ApiResponse::success(app(Administration::class)->approve($r, $country));
    }

    public function diagnostics(Request $r, string $merchant): mixed
    {
        return ApiResponse::success(app(Administration::class)->diagnostics($r, $merchant));
    }

    public function reviewData(Request $r, string $merchant, string $declaration): mixed
    {
        return ApiResponse::success(app(Administration::class)->reviewData($r, $merchant, $declaration))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, string $merchant, string $declaration): mixed
    {
        return ApiResponse::success(app(Administration::class)->review($r, $merchant, $declaration), 201);
    }
}
