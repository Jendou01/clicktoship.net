<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OTPHP\TOTP;

abstract class ManagementTestCase extends IdentityTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXP'; // Synthetic fixture only.

    protected function platformUser(string $role = 'platform.super_admin'): User
    {
        $u = $this->user();
        DB::table('user_mfa_credentials')->insert(['user_id' => $u->id, 'secret' => Crypt::encryptString(self::SECRET), 'confirmed_at' => now(), 'last_step' => -1, 'version' => 1]);
        DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'role_scope' => 'platform', 'created_at' => now()]);

        return $u;
    }

    protected function adminLogin(User $u): void
    {
        $this->jar = [];
        $this->login($u);
        // Each fixture's first use exercises a real TOTP challenge.
        $code = TOTP::createFromSecret(self::SECRET)->at(now()->timestamp);
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => $code])->assertOk();
    }

    protected function grant(User $actor, User $approver, string $merchant, array $capabilities = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review']): string
    {
        $this->adminLogin($actor);
        $jar = $this->jar;
        $id = $this->browser('POST', '/api/v1/admin/access-requests', ['merchant_id' => $merchant, 'capabilities' => $capabilities, 'reason' => 'Synthetic support case approval'])->assertCreated()->json('data.id');
        $this->adminLogin($approver);
        $this->browser('POST', "/api/v1/admin/access-requests/$id/approve", ['version' => 1])->assertOk();
        $this->jar = $jar;

        return $id;
    }

    protected function profilePayload(int $version = 0): array
    {
        return ['version' => $version, 'legal_name' => 'Synthetic Company', 'country' => 'SA', 'contact_name' => 'Test Contact', 'contact_email' => 'contact@example.test', 'contact_phone' => '+966501234567'];
    }

    protected function addressPayload(int $version = 0): array
    {
        return ['version' => $version, 'label' => 'Warehouse', 'contact_name' => 'Synthetic Contact', 'contact_phone' => '+966501234567', 'country' => 'SA', 'city' => 'Riyadh', 'postal_code' => '12345', 'line1' => 'Synthetic street 10'];
    }
}
