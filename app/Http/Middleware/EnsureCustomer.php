<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === Role::Customer, 403);

        return $next($request);
    }
}
