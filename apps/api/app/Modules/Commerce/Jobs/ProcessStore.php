<?php

namespace App\Modules\Commerce\Jobs;

use App\Modules\Commerce\Application\Work;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessStore implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly string $merchant, public readonly string $work)
    {
        $this->onQueue('bulk');
    }

    public function handle(): void
    {
        if (app(Work::class)->tick($this->merchant, $this->work)) {
            self::dispatch($this->merchant, $this->work);
        }
    }
}
