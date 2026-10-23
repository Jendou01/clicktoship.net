<?php

namespace App\Modules\Merchants\Infrastructure;

use App\Modules\Identity\Infrastructure\PrivateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TeamInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use PrivateMail, Queueable;

    public $tries = 3;

    public function __construct(private string $token, private string $language) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function toMail($notifiable): MailMessage
    {
        $ar = $this->language === 'ar';

        return (new MailMessage)->subject($ar ? 'دعوة للانضمام إلى فريق ClickToShip' : 'Your ClickToShip team invitation')
            ->line($ar ? 'سجل الدخول بالبريد المدعو وأكد بريدك لقبول الدعوة خلال 72 ساعة.' : 'Sign in with the invited email and verify your email to accept within 72 hours.')
            ->action($ar ? 'قبول الدعوة' : 'Accept invitation', rtrim(config('app.url'), '/').'/'.$this->language.'/invitation#invitation='.$this->token);
    }
}
