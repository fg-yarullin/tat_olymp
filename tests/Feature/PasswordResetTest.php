<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_to_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'coord@example.com', 'role' => UserRole::Admin, 'is_active' => true]);

        $this->post(route('password.email'), ['email' => 'coord@example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_email_is_in_russian(): void
    {
        $user = User::factory()->create();
        $mail = (new ResetPassword('test-token'))->toMail($user);

        $this->assertSame('Восстановление пароля — РИСО Тат-олимп', $mail->subject);
        $this->assertStringContainsString('Сбросить пароль', $mail->actionText);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'u@example.com']);

        $this->post(route('password.email'), ['email' => 'u@example.com']);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.store'), [
                'token' => $notification->token,
                'email' => 'u@example.com',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-123', $user->fresh()->password));
    }
}
