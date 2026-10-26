<?php

namespace App\Support\Security;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

final class SafeFailedJobs extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        return parent::log($connection, $queue, $payload, new SafeQueueFailure($exception));
    }
}
