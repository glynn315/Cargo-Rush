<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordLink;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Getting back in.
 *
 * Until this existed the system had no answer to a forgotten password: every
 * account was made by somebody else, so recovery meant a person with terminal
 * access running `cargo:user`. Workable for one office; impossible the moment
 * strangers can register their own company.
 *
 * The property worth pinning hardest is the quiet one — **the response says
 * nothing about whether the address is known**. On a platform hosting hauliers
 * who compete with each other, "does this person have an account here" is a
 * real question somebody would like answered, and this endpoint is the obvious
 * place to ask it.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->user = User::create([
        'name' => 'Ana Cruz',
        'email' => 'ana@haulage.test',
        'password' => 'OldPassw0rd!',
        'role' => 'administrator',
    ]);
});

describe('asking for a link', function (): void {
    it('sends one to an address that has an account', function (): void {
        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])
            ->assertOk()
            ->assertJsonPath('data.message', 'If that address has an account, a reset link is on its way to it.');

        Notification::assertSentTo($this->user, ResetPasswordLink::class);
    });

    /**
     * The same sentence, and the same status, for an address nobody has.
     *
     * If these two answers differed in any way — the wording, the code, a
     * field — this endpoint would be a way of enumerating who is on the
     * platform.
     */
    it('says exactly the same thing for an address that does not', function (): void {
        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test']);
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@nowhere.test']);

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->json())->toBe($known->json());

        // One email, for the one address that exists. Counting is the honest
        // check here: there is no notifiable to assert *nothing* was sent to,
        // because the whole point is that no such account exists.
        Notification::assertCount(1);
    });

    /**
     * The link points at the web app, not at the API.
     *
     * Laravel's own notification builds its URL from a named web route, which
     * this application does not have. Left alone it would have emailed people
     * an address that 404s.
     */
    it('points the link at the front end, carrying the token and the address', function (): void {
        config(['app.frontend_url' => 'https://app.cargorush.test']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordLink::class, function ($notification): bool {
            $url = $notification->toMail($this->user)->actionUrl;

            return str_starts_with($url, 'https://app.cargorush.test/reset-password?token=')
                && str_contains($url, 'email=ana%40haulage.test');
        });
    });

    it('is metered, so it cannot be used to mail somebody a hundred times', function (): void {
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test']);
        }

        $this->postJson('/api/v1/forgot-password', ['email' => 'ana@haulage.test'])
            ->assertStatus(429);
    });
});

describe('using the link', function (): void {
    /** The token as the broker issues it — the same one the email carries. */
    function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    it('sets the new password and lets them straight back in', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();
    });

    /**
     * Somebody resetting a password often believes their account is taken.
     *
     * A driver's handset holds a long-lived bearer token. Leaving those alive
     * through a reset would mean the reset changed nothing for the one person
     * it was performed to remove.
     */
    it('revokes every device token, because that is usually the point', function (): void {
        $token = $this->user->createToken('their-phone')->plainTextToken;

        // It worked a moment ago, which is what makes the assertion below mean
        // something.
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertOk();

        // A harness artifact, not a product one: the test application resolves
        // its guards once and keeps them for the rest of the test, so without
        // this the next call is answered by the user already in memory rather
        // than by looking the token up again. A real client is a new process
        // every time and has nothing cached.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('refuses a token that has already been used', function (): void {
        $token = tokenFor($this->user);

        $reset = fn () => $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ]);

        $reset()->assertOk();
        $reset()->assertStatus(422)->assertJsonValidationErrors('token');
    });

    /**
     * The error goes on the token, not the password.
     *
     * Blaming the password sends somebody to invent a stronger one, over and
     * over, when what expired was the link.
     */
    it('blames the link rather than the password', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'This reset link is no longer valid. Ask for a new one.');
    });

    it('holds a reset password to the same strength registration asks for', function (): void {
        $this->postJson('/api/v1/reset-password', [
            'token' => tokenFor($this->user),
            'email' => $this->user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    });
});

describe('changing it while signed in', function (): void {
    it('changes the password when the current one is given', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertNoContent();

        $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'BrandNewPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();
    });

    /**
     * The rule that matters: a signed-in session on an unlocked laptop is
     * exactly where somebody else changes the password and takes the account.
     */
    it('refuses without the current password', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'guessing',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'That is not your current password.');
    });

    /**
     * Unlike a reset, this keeps other devices signed in.
     *
     * Somebody choosing a stronger password on purpose has not lost control of
     * anything, and signing their own phone out for it teaches them not to
     * bother next time.
     */
    it('leaves other devices signed in', function (): void {
        $token = $this->user->createToken('their-phone')->plainTextToken;

        $this->actingAs($this->user)->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertNoContent();

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    });

    it('turns away an unauthenticated caller', function (): void {
        $this->postJson('/api/v1/me/password', [
            'current_password' => 'OldPassw0rd!',
            'password' => 'BrandNewPassw0rd!',
            'password_confirmation' => 'BrandNewPassw0rd!',
        ])->assertUnauthorized();
    });
});
