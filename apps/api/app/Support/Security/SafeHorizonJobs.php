<?php

namespace App\Support\Security;

use Laravel\Horizon\JobPayload;
use Laravel\Horizon\Repositories\RedisJobRepository;

final class SafeHorizonJobs extends RedisJobRepository
{
    public function failed($exception, $connection, $queue, JobPayload $payload)
    {
        parent::failed(new SafeQueueFailure($exception), $connection, $queue, $payload);
    }
}
