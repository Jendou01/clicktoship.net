<?php

namespace Tests\Fixtures;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Membership;
use App\Modules\Merchants\Jobs\UseMerchantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

final class TenantQueueProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $merchant, public string $actor, public string $resultKey) {}

    public function middleware(): array
    {
        return [new UseMerchantContext($this->merchant, $this->actor, 'merchant.view')];
    }

    public function handle(): void
    {
        Redis::setex($this->resultKey, 60, json_encode(['merchant' => app(MerchantContext::class)->id(), 'memberships' => Membership::count()]));
    }
}
