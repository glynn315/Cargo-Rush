<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the caller's company in force for the rest of the request.
 *
 * Runs immediately inside `auth:sanctum`, before any controller, so every query
 * a request makes is already filtered by the time a repository is reached.
 *
 * The company comes off the authenticated account, and there is deliberately no
 * other way to set it — no header, no query parameter, no path segment. A
 * client cannot ask to be a different company because a client is never asked
 * which company it is. That is the whole security argument for this design, and
 * it is why the login form did not need a third field.
 *
 * An account whose company row has been deleted is turned away rather than
 * being let through unscoped. Unscoped is not "no data" — it is *all* data, and
 * a bug that fails open here is the one failure this system cannot have.
 *
 * One account has no company and is nonetheless let in: a shipper who has
 * registered and not yet chosen a haulier. It is let in scoped to
 * `Tenant::NOBODY` rather than to nothing, which is the same fail-closed
 * reading applied a different way — a company no row has, so the reads answer
 * empty and the writes are refused. What they can do is browse the carrier
 * directory and file the request that opens them an account, which is the whole
 * of what a shipper with no carrier should be able to do.
 */
class BindTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 401, matching `RequirePermission`: the clients send a 401 to the
        // login screen, and this is the same "you are not signed in" answer.
        abort_if(! $user instanceof User, 401);

        $company = $user->company;

        // Registered, no carrier chosen yet. Not a broken row — a waiting one,
        // and it stops waiting on their first request. See
        // `User::awaitingCarrier()`.
        if ($company === null && $user->awaitingCarrier()) {
            app(Tenant::class)->nobody();

            return $next($request);
        }

        abort_if(
            $company === null,
            403,
            'This account is not attached to a company. Ask an administrator to reattach it.',
        );

        abort_if(
            ! $company->isActive(),
            403,
            sprintf('%s is suspended. Contact support to reactivate the account.', $company->name),
        );

        app(Tenant::class)->set($company);

        return $next($request);
    }
}
