<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only active parents of an active school into the parent portal.
 */
class EnsureParentAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user->hasRole(Role::Parent) && $user->guardian !== null, Response::HTTP_FORBIDDEN);

        if (! $user->is_active || ! $user->school?->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Votre accès parent est désactivé. Contactez l\'école.',
            ]);
        }

        return $next($request);
    }
}
