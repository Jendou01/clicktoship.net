<?php

namespace App\Modules\Merchants\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class Invitations
{
    public function __construct(private MerchantContext $context, private Management $management, private TeamService $team) {}

    public function index(): array
    {
        $this->context->require('team.manage');

        return ['invitations' => DB::table('merchant_invitations as i')->join('roles as r', 'r.id', '=', 'i.role_id')->where('i.merchant_id', $this->context->id())->orderByDesc('i.created_at')->limit(100)->get(['i.id', 'i.email', 'r.code as role', 'i.version', 'i.expires_at', 'i.accepted_at', 'i.revoked_at'])->map(function ($row) {
            $row->email = Crypt::decryptString($row->email);

            return $row;
        })];
    }

    private function grantable(string $code): object
    {
        $this->context->require('team.manage');
        $role = DB::table('roles')->where('scope', 'merchant')->where('code', $code)->first();
        abort_unless($role, 422);
        $current = DB::table('merchant_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.membership_id', $this->context->membershipId())->value('r.code');
        abort_if($code === 'merchant.owner' && $current !== 'merchant.owner', 403);
        $grants = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->pluck('p.code')->all();
        abort_if(array_diff($grants, $this->context->permissions()) !== [], 403);

        return $role;
    }

    public function store(Request $r): array
    {
        $d = $r->validate(['email' => 'required|email:rfc|max:254', 'role' => 'required|string|max:80', 'merchant_id' => 'prohibited']);

        return DB::transaction(function () use ($r, $d) {
            $m = $this->management->lock('team.manage');
            $role = $this->grantable($d['role']);
            $email = strtolower($d['email']);
            $hash = hash_hmac('sha256', $email, config('app.key'));
            DB::table('merchant_invitations')->where('merchant_id', $m->id)->where('email_hash', $hash)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now())->update(['revoked_at' => now()]);
            abort_if(DB::table('merchant_invitations')->where('merchant_id', $m->id)->where('email_hash', $hash)->whereNull('accepted_at')->whereNull('revoked_at')->exists(), 409);
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            DB::table('merchant_invitations')->insert(['id' => $id, 'merchant_id' => $m->id, 'email' => Crypt::encryptString($email), 'email_hash' => $hash, 'role_id' => $role->id, 'role_scope' => 'merchant', 'invited_by' => $r->user()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72), 'created_at' => now(), 'updated_at' => now()]);
            $this->send($email, $token, $r->user()->locale);
            Audit::record('membership.invited', $r->user()->id, $m->id, $id, ['role' => $role->code]);

            return ['id' => $id, 'version' => 1];
        });
    }

    private function send(string $email, string $token, string $locale): void
    {
        Notification::route('mail', $email)->notify((new TeamInvitation($token, $locale))->afterCommit());
    }

    public function change(Request $r, string $id, bool $resend): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $resend, $d) {
            $m = $this->management->lock('team.manage');
            $i = DB::table('merchant_invitations')->where('id', $id)->where('merchant_id', $m->id)->lockForUpdate()->first();
            abort_unless($i, 404);
            abort_unless($i->version === $d['version'] && ! $i->accepted_at && ! $i->revoked_at, 409);
            $role = DB::table('roles')->where('id', $i->role_id)->first();
            $this->grantable($role->code);
            $data = ['version' => $i->version + 1, 'updated_at' => now()];
            if ($resend) {
                // Resending on another user's behalf must not launder revoked inviter authority.
                $data['invited_by'] = $r->user()->id;
                $token = bin2hex(random_bytes(32));
                $data['token_hash'] = hash('sha256', $token);
                $data['expires_at'] = now()->addHours(72);
                $this->send(Crypt::decryptString($i->email), $token, $r->user()->locale);
            } else {
                $data['revoked_at'] = now();
            }
            DB::table('merchant_invitations')->where('id', $id)->update($data);
            Audit::record($resend ? 'membership.invite_resent' : 'membership.invite_revoked', $r->user()->id, $m->id, $id);

            return ['version' => $data['version']];
        });
    }

    public function accept(Request $r): array
    {
        $d = $r->validate(['token' => 'required|string|size:64', 'merchant_id' => 'prohibited', 'role' => 'prohibited']);

        return DB::transaction(function () use ($r, $d) {
            $i = DB::table('merchant_invitations')->where('token_hash', hash('sha256', $d['token']))->first();
            abort_unless($i, 422);
            DB::table('merchants')->where('id', $i->merchant_id)->lockForUpdate()->first();
            $i = DB::table('merchant_invitations')->where('id', $i->id)->lockForUpdate()->first();
            abort_unless(! $i->accepted_at && ! $i->revoked_at && now()->lt($i->expires_at), 422);
            abort_unless(hash_equals($i->email_hash, hash_hmac('sha256', strtolower($r->user()->email), config('app.key'))), 403);
            $inviter = User::findOrFail($i->invited_by);
            try {
                $this->context->establish($inviter, $i->merchant_id);
                $role = DB::table('roles')->where('id', $i->role_id)->first();
                $this->grantable($role->code);
                // Existing memberships require explicit management; invitations cannot overwrite roles.
                $member = $this->team->add(['email' => $r->user()->email, 'role' => $role->code]);
                DB::table('merchant_invitations')->where('id', $i->id)->update(['accepted_at' => now(), 'version' => $i->version + 1, 'updated_at' => now()]);
                Audit::record('membership.invite_accepted', $r->user()->id, $i->merchant_id, $i->id);

                return ['membership_id' => $member->id];
            } finally {
                $this->context->clear();
            }
        });
    }
}
