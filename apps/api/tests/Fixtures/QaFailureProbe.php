<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class QaFailureProbe implements ShouldBeEncrypted, ShouldQueue
{
    public function __construct(public string $diagnostic) {}

    public function handle(): void
    {
        abort_unless(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'clicktoship_test', 403);
        throw new \RuntimeException($this->diagnostic);
    }
}
