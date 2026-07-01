<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Письмо для самостоятельного сброса пароля — на русском (UI системы русскоязычный).
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Восстановление пароля — РИСО Тат-олимп')
                ->greeting('Здравствуйте!')
                ->line('Мы получили запрос на сброс пароля для вашей учётной записи.')
                ->action('Сбросить пароль', $url)
                ->line("Ссылка действительна {$minutes} минут.")
                ->line('Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.')
                ->salutation('С уважением, РИСО Тат-олимп');
        });
    }
}
