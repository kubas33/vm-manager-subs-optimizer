<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAppVerification
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('auth.disable_auth')) {
            return $next($request);
        }

        /** @var EnsureEmailIsVerified $ensureEmailIsVerified */
        $ensureEmailIsVerified = app(EnsureEmailIsVerified::class);

        return $ensureEmailIsVerified->handle($request, $next);
    }
}
