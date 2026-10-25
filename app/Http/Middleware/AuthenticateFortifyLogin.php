<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

final class AuthenticateFortifyLogin
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $username = Fortify::username();
        $email = mb_strtolower(trim((string) $request->input($username)));
        $guard = Auth::guard(config('fortify.guard'));

        if (! $guard->attempt([
            $username => $email,
            'password' => $request->input('password'),
            'is_active' => true,
        ], $request->boolean('remember'))) {
            RateLimiter::hit(EnsureFortifyLoginIsNotThrottled::key($request), 900);

            throw ValidationException::withMessages([
                $username => [trans('auth.failed')],
            ]);
        }

        RateLimiter::clear(EnsureFortifyLoginIsNotThrottled::key($request));

        return $next($request);
    }
}
