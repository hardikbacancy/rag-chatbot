<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs every visitor in as one shared account when AUTH_GUEST_MODE is on, so the
 * app can run without a login screen while every downstream query keeps filtering
 * by $request->user(). Turning the flag off restores real accounts with no other
 * change; the rows created while it was on simply belong to the guest account.
 */
class AuthenticateAsGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('auth.guest_mode') && ! Auth::check()) {
            Auth::login($this->guestUser());
        }

        return $next($request);
    }

    private function guestUser(): User
    {
        return User::firstOrCreate(
            ['email' => config('auth.guest_user.email')],
            [
                'name' => config('auth.guest_user.name'),
                // Never used for signing in; the account is only reachable through
                // this middleware, so the password must not be guessable.
                'password' => Str::random(64),
            ],
        );
    }
}
