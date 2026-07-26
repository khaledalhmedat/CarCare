<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtpNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $otp, protected int $ttlMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رمز إعادة تعيين كلمة المرور')
            ->greeting('مرحباً')
            ->line('لقد طلبت إعادة تعيين كلمة المرور الخاصة بك.')
            ->line('رمز التحقق الخاص بك هو: ' . $this->otp)
            ->line('هذا الرمز صالح لمدة ' . $this->ttlMinutes . ' دقائق.')
            ->line('إذا لم تطلب ذلك، يمكنك تجاهل هذه الرسالة.');
    }
}
