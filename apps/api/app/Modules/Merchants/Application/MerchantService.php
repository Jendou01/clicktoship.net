<?php

namespace App\Modules\Merchants\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Infrastructure\Merchant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MerchantService
{
    public function create(User $user, string $name): Merchant
    {
        return DB::transaction(function () use ($user, $name): Merchant {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->suspended_at, 403);
            $merchant = Merchant::create(['name' => $name]);
            $member = (string) Str::uuid();
            DB::table('merchant_users')->insert([
                'id' => $member, 'merchant_id' => $merchant->id, 'user_id' => $user->id,
                'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('merchant_role_assignments')->insert([
                'id' => (string) Str::uuid(), 'merchant_id' => $merchant->id, 'membership_id' => $member,
                'role_id' => DB::table('roles')->where('code', 'merchant.owner')->value('id'), 'role_scope' => 'merchant',
            ]);
            Audit::record('merchant.created', $user->id, $merchant->id, $merchant->id);

            return $merchant;
        });
    }
}
