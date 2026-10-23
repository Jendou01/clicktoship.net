<?php

namespace App\Modules\Merchants\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Membership;
use App\Modules\Merchants\Infrastructure\Merchant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamService
{
    public function __construct(private MerchantContext $context) {}

    private function lockAndAuthorize(): void
    {
        $merchant = Merchant::whereKey($this->context->id())->lockForUpdate()->firstOrFail();
        abort_unless($merchant->status === 'active', 403);
        // Re-read role, membership and user status AFTER acquiring the common lock.
        $this->context->require('team.manage');
    }

    private function role(string $code, ?string $targetRole = null): object
    {
        $role = DB::table('roles')->where('code', $code)->where('scope', 'merchant')->first();
        if (! $role) {
            throw ValidationException::withMessages(['role' => ['Select a merchant role.']]);
        }
        $actorRole = DB::table('merchant_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.membership_id', $this->context->membershipId())->value('r.code');
        if ($code === 'merchant.owner' || $targetRole === 'merchant.owner') {
            abort_unless($actorRole === 'merchant.owner', 403);
        }
        $grants = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('p.code')->all();
        abort_if(array_diff($grants, $this->context->permissions()) !== [], 403);

        return $role;
    }

    public function add(array $data): Membership
    {
        return DB::transaction(function () use ($data): Membership {
            $this->lockAndAuthorize();
            $role = $this->role($data['role']);
            $user = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->whereNull('suspended_at')->whereNotNull('email_verified_at')->lockForUpdate()->first();
            if (! $user) {
                throw ValidationException::withMessages(['email' => ['A verified registered account is required.']]);
            }
            abort_if(Membership::where('user_id', $user->id)->exists(), 409);
            $member = Membership::create(['user_id' => $user->id, 'status' => 'active', 'version' => 1]);
            DB::table('merchant_role_assignments')->insert([
                'id' => (string) Str::uuid(), 'merchant_id' => $this->context->id(),
                'membership_id' => $member->id, 'role_id' => $role->id, 'role_scope' => 'merchant',
            ]);
            Audit::record('membership.added', $this->context->actorId(), $this->context->id(), $member->id, ['role' => $role->code]);

            return $member;
        }, 3);
    }

    public function update(string $id, array $data): Membership
    {
        return DB::transaction(function () use ($id, $data): Membership {
            $this->lockAndAuthorize();
            $member = Membership::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version === $data['version'], 409);
            $old = DB::table('merchant_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.membership_id', $member->id)->value('r.code');
            $role = $this->role($data['role'] ?? $old, $old);
            $status = $data['status'] ?? $member->status;
            if ($status === 'active') {
                $eligible = User::whereKey($member->user_id)->whereNull('suspended_at')->whereNotNull('email_verified_at')->lockForUpdate()->first();
                abort_unless($eligible, 409);
            }
            $losesOwnership = $old === 'merchant.owner' && $member->status === 'active' && ($role->code !== 'merchant.owner' || $status !== 'active');
            if ($losesOwnership) {
                $owners = DB::table('merchant_users as m')->join('merchant_role_assignments as a', 'a.membership_id', '=', 'm.id')
                    ->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'm.user_id')
                    ->where('m.merchant_id', $this->context->id())->where('m.status', 'active')->whereNull('u.suspended_at')->where('r.code', 'merchant.owner')->count();
                abort_if($owners <= 1, 409);
            }
            $member->status = $status;
            $member->version++;
            $member->save();
            if ($status !== 'active' || $role->code !== $old) {
                DB::table('personal_access_tokens')->where('merchant_id', $this->context->id())->where('tokenable_id', $member->user_id)->delete();
            }
            DB::table('merchant_role_assignments')->where('membership_id', $member->id)->update(['role_id' => $role->id]);
            Audit::record('membership.updated', $this->context->actorId(), $this->context->id(), $member->id, ['role' => $role->code, 'status' => $status, 'version' => $member->version]);

            return $member;
        }, 3);
    }
}
