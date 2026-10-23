<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PricingTestCase;

final class ConcurrentPricingTest extends PricingTestCase
{
    use DatabaseMigrations;

    private function race(array $a, array $b): array
    {
        $path = sys_get_temp_dir().'/pricing-'.Str::uuid();
        $worker = fn ($d) => (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-pricing.php'], base_path()))->setInput(json_encode($d))->setTimeout(15);
        $first = $worker([...$a, 'ready' => $path, 'go' => $path.'.go']);
        $second = $worker($b);
        try {
            $first->start();
            $deadline = microtime(true) + 8;
            while (! is_file($path) && microtime(true) < $deadline) {
                usleep(1000);
            }$this->assertFileExists($path);
            $second->start();
            do {
                $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'")->n;
                if (! $waiting) {
                    usleep(1000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertGreaterThan(0, (int) $waiting);
            file_put_contents($path.'.go', 'go');
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

            return [json_decode($first->getOutput(), true), json_decode($second->getOutput(), true)];
        } finally {
            @unlink($path);
            @unlink($path.'.go');
            if ($first->isRunning()) {
                $first->stop();
            }if ($second->isRunning()) {
                $second->stop();
            }
        }
    }

    private function quotePayload(): array
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $u->createToken('Pricing concurrency', ['rates.quote', 'rates.read'], now()->addDay())->accessToken;
        $token->forceFill(['merchant_id' => $m, 'auth_version' => $u->auth_version])->save();

        return ['mode' => 'quote', 'actor' => $u->id, 'merchant' => $m, 'token' => $token->id, 'payload' => $this->quoteInput($f['service'])];
    }

    public function test_concurrent_replays_create_exactly_one_quote(): void
    {
        $a = $this->quotePayload();
        $r = $this->race($a, $a);
        $this->assertSame(['ok', 'ok'], array_column($r, 'status'));
        $this->assertSame($r[0]['result'], $r[1]['result']);
        $this->assertDatabaseCount('shipping_quotes', 1);
        $this->assertDatabaseCount('pricing_quote_requests', 1);
    }

    public function test_concurrent_changed_replay_conflicts(): void
    {
        $a = $this->quotePayload();
        $b = $a;
        $b['payload']['packages'][0]['weight_grams'] = 2000;
        $r = $this->race($a, $b);
        $this->assertSame(['ok', 409], array_column($r, 'status'));
        $this->assertDatabaseCount('shipping_quotes', 1);
    }

    public function test_suspension_winning_lock_blocks_quote(): void
    {
        $a = $this->quotePayload();
        $r = $this->race([...$a, 'mode' => 'suspend'], $a);
        $this->assertSame(['ok', 404], array_column($r, 'status'));
        $this->assertDatabaseCount('shipping_quotes', 0);
    }

    public function test_concurrent_admin_changes_cannot_overwrite_and_revocation_blocks_writer(): void
    {
        $u = $this->platformUser();
        $this->adminLogin($u);
        $payload = ['kind' => 'tier', 'code' => 'race-tier', 'version' => 0, 'effective_from' => now()->toIso8601String(), 'effective_to' => now()->addDay()->toIso8601String(), 'configuration' => ['name_en' => 'Synthetic tier', 'name_ar' => 'فئة اختبار', 'evidence' => 'Synthetic race fixture only']];
        $row = $this->browser('POST', '/api/v1/admin/pricing/definitions', $payload)->assertCreated()->json('data');
        $payload['version'] = 1;
        $a = ['mode' => 'edit', 'actor' => $u->id, 'definition' => $row['id'], 'payload' => $payload, 'session' => app('session.store')->get('auth_session_id'), 'raw_session' => app('session.store')->getId()];
        $r = $this->race($a, $a);
        $this->assertSame(['ok', 409], array_column($r, 'status'));
        $this->assertDatabaseCount('pricing_versions', 2);
        $a['payload']['version'] = 2;
        $r = $this->race([...$a, 'mode' => 'revoke'], $a);
        $this->assertSame(['ok', 401], array_column($r, 'status'));
        $this->assertDatabaseCount('pricing_versions', 2);
    }
}
