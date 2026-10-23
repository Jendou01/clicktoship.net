<?php

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends BaseVerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use PrivateMail, Queueable;

    public $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function toMail($notifiable): MailMessage
    {
        $arabic = $notifiable->locale === 'ar';

        return (new MailMessage)
            ->subject($arabic ? 'تأكيد بريدك الإلكتروني' : 'Verify your ClickToShip email')
            ->line($arabic ? 'أكد بريدك للوصول إلى منشآتك. ينتهي الرابط خلال 60 دقيقة.' : 'Verify your email to access your organizations. This link expires in 60 minutes.')
            ->action($arabic ? 'تأكيد البريد' : 'Verify email', $this->verificationUrl($notifiable))
            ->line($arabic ? 'إذا لم تنشئ هذا الحساب، فتجاهل الرسالة.' : 'If you did not create this account, ignore this email.');
    }

    protected function verificationUrl($notifiable): string
    {
        $signed = URL::temporarySignedRoute('auth.email.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification()),
        ], absolute: false);

        return rtrim(config('app.url'), '/').'/'.$notifiable->locale.'/verify-email#verification='.rawurlencode($signed);
    }
}
