<?php

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends BaseResetPassword implements ShouldBeEncrypted, ShouldQueue
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
            ->subject($arabic ? 'استعادة كلمة المرور' : 'Reset your ClickToShip password')
            ->line($arabic ? 'طلبت استعادة كلمة المرور. ينتهي الرابط خلال 60 دقيقة.' : 'A password reset was requested. This link expires in 60 minutes.')
            ->action($arabic ? 'تعيين كلمة مرور جديدة' : 'Reset password', $this->resetUrl($notifiable))
            ->line($arabic ? 'إذا لم تطلب ذلك، فتجاهل الرسالة.' : 'If you did not request this, ignore this email.');
    }

    protected function resetUrl($notifiable): string
    {
        return rtrim(config('app.url'), '/').'/'.$notifiable->locale.'/reset-password#'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
