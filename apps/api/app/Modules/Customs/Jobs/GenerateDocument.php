<?php

namespace App\Modules\Customs\Jobs;

use App\Modules\Customs\Application\DocumentWork;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateDocument implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $merchant, public readonly string $work)
    {
        $this->onQueue('customs');
    }

    public function handle(): void
    {
        app(DocumentWork::class)->tick($this->merchant, $this->work);
    }
}
