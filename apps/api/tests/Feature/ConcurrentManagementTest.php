<?php

namespace Tests\Feature;

use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

class ConcurrentManagementTest extends ManagementTestCase
{
    use DatabaseMigrations;

    private function race(array $first, array $second): array
    {
        $ready = sys_get_temp_dir().'/membership-barrier-'.Str::uuid();
        $worker = fn (array $payload) => (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-management.php'], base_path()))->setInput(json_encode($payload))->setTimeout(10);
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

    public function test_concurrent_settings_edits_cannot_overwrite_a_new_version(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $base = ['mode' => 'settings', 'actor' => $u->id, 'merchant' => $m];
        $this->assertSame([200, 409], $this->race([...$base, 'change' => ['version' => 0, 'locale' => 'en', 'timezone' => 'UTC']], [...$base, 'change' => ['version' => 0, 'locale' => 'ar', 'timezone' => 'Asia/Riyadh']]));
        $this->assertSame(1, DB::table('merchant_settings')->where('merchant_id', $m)->value('version'));
    }

    public function test_two_super_admins_cannot_concurrently_remove_the_last_usable_admin(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $sa = DB::table('auth_sessions')->where('user_id', $a->id)->value('id');
        $this->adminLogin($b);
        $sb = DB::table('auth_sessions')->where('user_id', $b->id)->value('id');
        $change = ['version' => 1, 'role' => 'platform.super_admin', 'assigned' => false, 'reason' => 'Synthetic concurrent admin removal'];
        $statuses = $this->race(['mode' => 'role', 'actor' => $a->id, 'target' => $a->id, 'session' => $sa, 'change' => $change], ['mode' => 'role', 'actor' => $b->id, 'target' => $b->id, 'session' => $sb, 'change' => $change]);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, PlatformSecurity::usableAdmins());
    }

    public function test_user_suspension_and_new_owner_assignment_cannot_create_an_unusable_owner(): void
    {
        $admin = $this->platformUser();
        $target = $this->user();
        $owner = $this->user();
        $merchant = $this->organization($owner);
        $this->adminLogin($admin);
        $session = DB::table('auth_sessions')->where('user_id', $admin->id)->value('id');
        $this->assertSame([200, 422], $this->race(
            ['mode' => 'userstatus', 'actor' => $admin->id, 'target' => $target->id, 'session' => $session, 'change' => ['version' => 1, 'suspended' => true, 'reason' => 'Synthetic concurrent suspension']],
            ['mode' => 'add', 'actor' => $owner->id, 'merchant' => $merchant, 'change' => ['email' => $target->email, 'role' => 'merchant.owner']],
        ));
        $this->assertDatabaseMissing('merchant_users', ['merchant_id' => $merchant, 'user_id' => $target->id]);
    }
}
