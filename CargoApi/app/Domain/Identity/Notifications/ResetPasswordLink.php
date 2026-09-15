<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one email this system sends.
 *
 * Laravel's own `ResetPassword` notification builds its link from a **named
 * web route**, which this application does not have — there are no Blade views
 * and no web pages, only an API and two clients. Left as it was, the address
 * in the email would have pointed at a route that does not exist.
 *
 * So the link is built against the SPA instead. The token and the address ride
 * in the query string, the browser lands on a form in CargoUI, and that form
 * posts both back to `POST /api/v1/reset-password`. The API is still the only
 * thing that verifies anything; the web app is just where the person types.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Cargo Rush password')
            ->greeting('Reset your password')
            ->line('Somebody asked to reset the password for this address. If that was you, use the button below.')
            ->action('Choose a new password', $this->url($notifiable))
            ->line("This link stops working in {$minutes} minutes.")
            // Said plainly, because the common case for an unexpected reset
            // email is somebody mistyping their own address — not an attack.
            // "Ignore this" is the correct instruction and the reassuring one.
            ->line('If you did not ask for this, nothing has changed and you can ignore this email.');
    }

    /**
     * Where the link goes.
     *
     * The address is carried alongside the token because the reset form has to
     * post both, and the person following a link from their inbox has not
     * signed in — there is no session to read it from. It is not a secret: the
     * token is what proves anything, and it is single-use and expires.
     *
     * **No company anywhere in this URL**, and that is the earlier decision
     * paying off. An email address belongs to exactly one account, so the
     * token identifies the account and the account names the company. A reset
     * flow that had to know the tenant would need it in the link — and a link
     * that names a company is one that can be tampered with.
     */
    private function url(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return sprintf(
            '%s/reset-password?token=%s&email=%s',
            $base,
            $this->token,
            urlencode($notifiable->getEmailForPasswordReset()),
        );
    }
}
