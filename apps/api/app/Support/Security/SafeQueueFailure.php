<?php

namespace App\Support\Security;

/** Persistence-only representation: never replaces the exception seen by job logic. */
final class SafeQueueFailure extends \RuntimeException
{
    public function __construct(\Throwable $original)
    {
        $type = get_class($original);
        $type = preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $type) ? $type : 'Throwable';
        parent::__construct(json_encode(['code' => 'APPLICATION_QUEUE_FAILURE', 'exception_type' => $type, 'fingerprint' => hash('sha256', get_class($original).'|'.$original->getFile().'|'.$original->getLine())]));
    }

    public function __toString(): string
    {
        return $this->getMessage();
    }
}
