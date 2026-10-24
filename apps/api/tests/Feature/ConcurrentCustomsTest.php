<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CustomsTestCase;

final class ConcurrentCustomsTest extends CustomsTestCase
{
    use DatabaseMigrations;

    private function worker(array $f, string $method, string $path, array $body): Process
    {
        return (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-customs.php'], base_path()))->setInput(json_encode(['method' => $method, 'path' => $path, 'token' => $f['token'], 'body' => $body]))->setTimeout(30);
    }

    private function blocked(int $n): void
    {
        $until = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            if ((int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'")->n >= $n) {
                return;
            }usleep(1000);
        } while (microtime(true) < $until);
        $this->fail('Expected real PostgreSQL lock waiters');
    }

    private function race(array $f, Process $a, Process $b, ?callable $beforeCommit = null): array
    {
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        try {
            $a->start();
            $b->start();
            $this->blocked(2);
            if ($beforeCommit) {
                $beforeCommit();
            }DB::commit();
            $a->wait();
            $b->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());

            return [json_decode($a->getOutput(), true), json_decode($b->getOutput(), true)];
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($a->isRunning()) {
                $a->stop();
            }if ($b->isRunning()) {
                $b->stop();
            }
        }
    }

    public function test_simultaneous_edits_only_commit_one_new_revision(): void
    {
        $f = $this->customsFixture();
        $path = "/api/v1/customs/shipments/{$f['s']['id']}/declaration";
        $body = ['version' => 1, 'declaration_version' => 0, 'declaration' => $f['customs'], 'idempotency_key' => 'concurrent-declaration-A'];
        $a = $this->worker($f, 'PUT', $path, $body);
        $body['idempotency_key'] = 'concurrent-declaration-B';
        $b = $this->worker($f, 'PUT', $path, $body);
        $out = $this->race($f, $a, $b);
        $codes = array_column($out, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertDatabaseCount('customs_versions', 1);
        $this->assertDatabaseCount('shipment_revisions', 2);
    }

    public function test_simultaneous_invoice_requests_preserve_one_number_and_document(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $path = "/api/v1/customs/shipments/{$f['s']['id']}/documents";
        $body = ['version' => $s['shipment']['version'], 'declaration_version' => 1, 'kind' => 'invoice', 'locale' => 'en', 'idempotency_key' => 'concurrent-invoice-A'];
        $a = $this->worker($f, 'POST', $path, $body);
        $body['idempotency_key'] = 'concurrent-invoice-B';
        $b = $this->worker($f, 'POST', $path, $body);
        $out = $this->race($f, $a, $b);
        $this->assertSame([202, 202], array_column($out, 'status'));
        $this->assertSame($out[0]['data']['document_id'], $out[1]['data']['document_id']);
        $this->assertDatabaseCount('customs_documents', 1);
        $this->assertDatabaseCount('customs_document_results', 1);
        $this->assertDatabaseHas('customs_document_counters', ['number' => 2]);
    }

    public function test_revocation_winning_the_edit_fence_prevents_customs_commit(): void
    {
        $f = $this->customsFixture();
        $path = "/api/v1/customs/shipments/{$f['s']['id']}/declaration";
        $body = ['version' => 1, 'declaration_version' => 0, 'declaration' => $f['customs'], 'idempotency_key' => 'revocation-race-request'];
        $a = $this->worker($f, 'PUT', $path, $body);
        $b = $this->worker($f, 'PUT', $path, $body);
        $out = $this->race($f, $a, $b, fn () => DB::table('personal_access_tokens')->where('tokenable_id', $f['u']->id)->delete());
        $this->assertSame([403, 403], array_column($out, 'status'));
        $this->assertDatabaseCount('customs_versions', 0);
    }
}
