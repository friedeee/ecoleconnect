<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only active staff members of an active school into the school area.
 */
class EnsureSchoolAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active || ($user->school !== null && ! $user->school->is_active)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Votre compte est désactivé. Contactez l\'administration de votre école.',
            ]);
        }

        if ($user->hasRole(Role::SuperAdmin)) {
            return redirect()->route('platform.schools.index');
        }

        if ($user->hasRole(Role::Parent)) {
            return redirect()->route('parent.home');
        }

        abort_unless($user->school_id !== null && $user->hasRole(...Role::staffRoles()), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
