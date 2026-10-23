<?php

namespace App\Modules\Identity\Infrastructure;

use LogicException;

trait PrivateMail
{
    public function via($notifiable): array
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'array'], true)
            || ($transport === 'array' && app()->isProduction())) {
            throw new LogicException('Credential notifications require a private mail transport; logging and unsafe fallback are forbidden.');
        }

        return ['mail'];
    }
}
