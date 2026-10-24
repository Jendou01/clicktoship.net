<?php

namespace App\Modules\Tracking\Jobs;

use App\Modules\Tracking\Application\TrackingPollScheduler;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTracking implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $merchant, public readonly string $work)
    {
        $this->onQueue('tracking');
    }

    public function handle(): void
    {
        app(TrackingPollScheduler::class)->tick($this->merchant, $this->work);
    }
}
