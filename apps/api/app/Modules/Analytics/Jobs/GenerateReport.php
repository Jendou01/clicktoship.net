<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Application\Exports;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateReport implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly string $id)
    {
        $this->onQueue('bulk');
    }

    public function handle(): void
    {
        app(Exports::class)->tick($this->id);
    }
}
