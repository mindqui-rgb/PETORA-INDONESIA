<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

final class EnsureFortifyLoginIsNotThrottled
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $key = self::key($request);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($key),
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ])],
            ]);
        }

        return $next($request);
    }

    public static function key(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input(Fortify::username())));

        return 'login-failures:'.hash('sha256', $email);
    }
}
