<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Документация API: в local открыта, иначе только с Bearer-токеном Sanctum или токеном приложения.
 */
class ApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') || $request->user('sanctum') !== null || $request->user('app') !== null) {
            return $next($request);
        }

        abort(401);
    }
}
