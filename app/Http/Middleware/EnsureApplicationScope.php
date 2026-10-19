<?php

namespace App\Http\Middleware;

use App\Models\ApiApplication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Права приложения на раздел API (`scope:servers`): GET/HEAD — `servers:read`, остальное — `servers:write`.
 * Пользователей не ограничивает.
 */
class EnsureApplicationScope
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        $app = $request->user();
        if ($app instanceof ApiApplication) {
            $write = ! $request->isMethodSafe();
            abort_unless($app->allows($section, $write), 403, "У приложения нет права {$section}:".($write ? 'write' : 'read').'.');
        }

        return $next($request);
    }
}
