<?php

namespace Tests\Feature;

use App\Modules\Merchants\Application\TeamService;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityTestCase;

class ConcurrentMembershipTest extends IdentityTestCase
{
    use DatabaseMigrations;

    private function race(array $first, array $second): array
    {
        $ready = sys_get_temp_dir().'/membership-barrier-'.Str::uuid();
        $worker = fn (array $payload) => (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-membership.php'], base_path()))->setInput(json_encode($payload))->setTimeout(10);
        $a = $worker([...$first, 'ready' => $ready]);
        $b = $worker($second);
        try {
            $a->start();
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($ready, 'First transaction did not acquire the PostgreSQL lock');
            $b->start();
            $a->wait();
            $b->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());

            return [json_decode($a->getOutput(), true)['status'], json_decode($b->getOutput(), true)['status']];
        } finally {
            @unlink($ready);
            if ($a->isRunning()) {
                $a->stop();
            } if ($b->isRunning()) {
                $b->stop();
            }
        }
    }

    public function test_concurrent_same_version_changes_allow_one_write_only(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $merchant = $this->organization($owner);
        app(MerchantContext::class)->establish($owner, $merchant);
        $target = app(TeamService::class)->add(['email' => $member->email, 'role' => 'merchant.viewer'])->id;
        $base = ['actor' => $owner->id, 'merchant' => $merchant, 'target' => $target];
        $this->assertSame([200, 409], $this->race([...$base, 'change' => ['version' => 1, 'role' => 'merchant.admin']], [...$base, 'change' => ['version' => 1, 'role' => 'merchant.finance_manager']]));
        $this->assertDatabaseHas('merchant_users', ['id' => $target, 'version' => 2]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'membership.updated')->count());
    }

    public function test_concurrent_owner_demotions_cannot_remove_the_last_owner(): void
    {
        $a = $this->user();
        $b = $this->user();
        $merchant = $this->organization($a);
        app(MerchantContext::class)->establish($a, $merchant);
        $bMember = app(TeamService::class)->add(['email' => $b->email, 'role' => 'merchant.owner'])->id;
        $aMember = DB::table('merchant_users')->where('merchant_id', $merchant)->where('user_id', $a->id)->value('id');
        $base = ['merchant' => $merchant, 'change' => ['version' => 1, 'role' => 'merchant.viewer']];
        $this->assertSame([200, 409], $this->race([...$base, 'actor' => $a->id, 'target' => $aMember], [...$base, 'actor' => $b->id, 'target' => $bMember]));
        $owners = DB::table('merchant_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.merchant_id', $merchant)->where('r.code', 'merchant.owner')->count();
        $this->assertSame(1, $owners);
    }

    public function test_waiting_request_is_reauthorized_after_its_actor_is_demoted(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        $merchant = $this->organization($a);
        app(MerchantContext::class)->establish($a, $merchant);
        $bMember = app(TeamService::class)->add(['email' => $b->email, 'role' => 'merchant.admin'])->id;
        $cMember = app(TeamService::class)->add(['email' => $c->email, 'role' => 'merchant.viewer'])->id;
        $this->assertSame([200, 403], $this->race(
            ['merchant' => $merchant, 'actor' => $a->id, 'target' => $bMember, 'change' => ['version' => 1, 'role' => 'merchant.viewer']],
            ['merchant' => $merchant, 'actor' => $b->id, 'target' => $cMember, 'change' => ['version' => 1, 'role' => 'merchant.admin']],
        ));
        $this->assertDatabaseHas('merchant_users', ['id' => $cMember, 'version' => 1]);
    }
}
