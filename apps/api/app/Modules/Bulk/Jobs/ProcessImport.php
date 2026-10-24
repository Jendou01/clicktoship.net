<?php

namespace App\Modules\Bulk\Jobs;

use App\Modules\Bulk\Application\Processor;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessImport implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $merchant, public readonly string $work)
    {
        $this->onQueue('bulk');
    }

    public function handle(): void
    {
        if (app(Processor::class)->tick($this->merchant, $this->work)) {
            self::dispatch($this->merchant, $this->work);
        }
    }
}
