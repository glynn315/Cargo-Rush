<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Tenancy\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request starts belonging to nobody in particular.
 *
 * First in the API group, ahead of `auth:sanctum` and therefore ahead of
 * `BindTenant`, and it does one thing: clears whatever company was last in
 * force in this process.
 *
 * The reason is the step between those two. Working out *who* is calling has to
 * happen unscoped — a bearer token and an email address are the two things in
 * this system that identify an account without already knowing its company,
 * which is the same argument `AuthService::login()` makes for looking a user up
 * across companies. If a company were still in force while Sanctum resolved the
 * token, the `users` row behind it would be filtered by that company, and an
 * account belonging to a different one would come back as "no such token" — a
 * 401 for a perfectly good credential.
 *
 * In a normal request there is nothing to clear: the container is built per
 * request and `Tenant` is a scoped binding, so it starts empty anyway. This is
 * for the processes that are reused — Octane, and the test suite, which drives
 * many requests through one container — where "nothing was in force before"
 * stops being true and a leftover tenant decides who the next caller is
 * allowed to be. That is not a property worth leaving to how the process
 * happens to be booted.
 *
 * It does not weaken anything. Unscoped lasts exactly as far as `BindTenant`,
 * which is inside the authenticated group and runs before any controller, so no
 * repository is ever reached with nothing in force.
 */
class ForgetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        app(Tenant::class)->forget();

        return $next($request);
    }
}
