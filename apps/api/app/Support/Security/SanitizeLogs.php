<?php

namespace App\Support\Security;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class SanitizeLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            $safe = [];
            foreach ($record->context as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_id') && is_string($value) && preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $value)) {
                    $safe[$key] = $value;
                } elseif (in_array($key, ['error_code', 'code'], true) && is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{1,100}$/D', $value)) {
                    $safe[$key] = $value;
                } elseif (in_array($key, ['count', 'attempt', 'duration_ms', 'queue_wait_ms', 'status'], true) && (is_int($value) || is_float($value))) {
                    $safe[$key] = $value;
                }
            }
            $exception = $record->context['exception'] ?? null;
            if ($exception instanceof \Throwable) {
                $type = get_class($exception);
                $safe['exception_type'] = preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $type) ? $type : 'Throwable';
                $safe['exception_fingerprint'] = hash('sha256', get_class($exception).'|'.$exception->getFile().'|'.$exception->getLine());
            }
            if (app()->bound('request') && is_string(request()->attributes->get('request_id'))) {
                $safe['request_id'] = request()->attributes->get('request_id');
            }
            $message = $exception ? 'APPLICATION_EXCEPTION' : (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $record->message) ? $record->message : 'APPLICATION_EVENT');

            return $record->with(message: $message, context: $safe, extra: []);
        });
    }
}
