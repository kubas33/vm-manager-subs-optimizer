<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAppAuthentication
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

        /** @var Authenticate $authenticate */
        $authenticate = app(Authenticate::class);

        return $authenticate->handle($request, $next);
    }
}
