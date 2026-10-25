<?php

namespace App\Modules\Operations\Http;

use App\Modules\MerchantDashboard\Application\Period;
use App\Modules\Operations\Application\Overview\Alerts;
use App\Modules\Operations\Application\Overview\Approvals;
use App\Modules\Operations\Application\Overview\Health;
use App\Modules\Operations\Application\Overview\Metrics;
use App\Modules\Operations\Application\Overview\ReadAuthority;
use App\Modules\Operations\Application\Overview\TargetSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OverviewController
{
    public function show(Request $r, ReadAuthority $gate, Metrics $metrics): mixed
    {
        $p = Period::read($r);
        $d = $r->validate(['section' => 'sometimes|in:merchants,users,shipping,bulk,tracking,carriers,pricing,finance,customs,commerce']);

        return $gate->respond($r, 'platform.overview', fn ($permissions) => $metrics->overview($permissions, $p, $d['section'] ?? null));
    }

    public function approvals(Request $r, ReadAuthority $gate, Approvals $approvals): mixed
    {
        return $gate->respond($r, 'platform.overview', fn ($p) => $approvals->read($p, $r->user()->id, $r->session()->get('auth_session_id')));
    }

    public function alerts(Request $r, ReadAuthority $gate, Alerts $alerts, ?string $alert = null): mixed
    {
        return $gate->respond($r, $alert ? 'platform.alerts.acknowledge' : 'platform.alerts.read', function ($p) use ($r, $alerts, $alert) {
            abort_unless(in_array('platform.alerts.read', $p, true), 403);

            return $alert ? $alerts->acknowledge($r, $p, $alert) : $alerts->read($p, $r->user()->id);
        });
    }

    public function health(Request $r, ReadAuthority $gate, Health $health): mixed
    {
        return $gate->respond($r, 'platform.health.view', fn () => $health->read());
    }

    public function settings(Request $r, ReadAuthority $gate, Health $health): mixed
    {
        return $gate->respond($r, 'platform.settings.view', fn () => $health->settings());
    }

    public function grants(Request $r, ReadAuthority $gate): mixed
    {
        return $gate->respond($r, 'platform.access.request', function ($permissions) use ($r) {
            $rows = DB::table('platform_access_grants')->where('actor_id', $r->user()->id)->where('session_id', $r->session()->get('auth_session_id'))->whereNotNull('approved_by')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('created_at')->orderByDesc('id')->limit(101)->get(['id', 'merchant_id', 'capabilities', 'expires_at']);
            $limited = $rows->count() > 100;
            foreach ($rows as $row) {
                $row->capabilities = array_values(array_intersect(json_decode($row->capabilities, true), $permissions));
            }

            return ['grants' => $rows->take(100)->filter(fn ($g) => count($g->capabilities) > 0)->values(), 'sample_limited' => $limited];
        });
    }

    public function target(Request $r, ReadAuthority $gate, TargetSummary $summary, string $merchant): mixed
    {
        // This route's path is an explicit target, never an aggregate scope selector.
        $period = Period::read($r);

        return $gate->respond($r, 'platform.overview', fn () => $summary->read($r, $merchant, $period), true);
    }

    public function activity(Request $r, ReadAuthority $gate, Metrics $metrics): mixed
    {
        $p = Period::read($r);
        $d = $r->validate(['cursor' => 'sometimes|string|max:300', 'action' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.]+$/']]);

        return $gate->respond($r, 'platform.audit.view', function () use ($d, $p, $metrics) {
            $q = $metrics->window(DB::table('audit_logs')->whereNull('merchant_id'), $p)->when($d['action'] ?? null, fn ($q, $s) => $q->where('action', $s));
            if (isset($d['cursor'])) {
                try {
                    $v = json_decode(base64_decode($d['cursor'], true), true, 8, JSON_THROW_ON_ERROR);
                    if (! is_array($v) || ! array_is_list($v) || count($v) !== 2 || ! is_string($v[0]) || ! is_string($v[1]) || ! Str::isUuid($v[1])) {
                        throw new \RuntimeException;
                    }$at = CarbonImmutable::parse($v[0])->toIso8601String();
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['cursor' => 'Invalid activity cursor.']);
                }
                $q->whereRaw('(created_at,id)<(?::timestamptz,?::uuid)', [$at, $v[1]]);
            }
            $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit(26)->get(['id', 'action', 'created_at']);
            $more = $rows->count() > 25;
            $rows = $rows->take(25)->values();
            $last = $rows->last();

            return ['period' => $p, 'events' => $rows, 'next_cursor' => $more ? base64_encode(json_encode([$last->created_at, $last->id])) : null];
        });
    }
}
