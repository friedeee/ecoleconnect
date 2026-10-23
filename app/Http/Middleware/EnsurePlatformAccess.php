<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only active platform operators into the platform area, where schools are opened and managed.
 */
class EnsurePlatformAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user->is_active && $user->hasRole(Role::SuperAdmin), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
