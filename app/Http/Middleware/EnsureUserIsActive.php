<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ejects deactivated users mid-session: is_active = false blocks
 * logins via canAccessPanel(), and this middleware logs out sessions
 * that were already authenticated when the flag flipped.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $login = str_starts_with($request->path(), 'admin') ? '/admin/login' : '/agent/login';

            return redirect()->to($login)->withErrors(['email' => 'Your account has been deactivated.']);
        }

        return $next($request);
    }
}
