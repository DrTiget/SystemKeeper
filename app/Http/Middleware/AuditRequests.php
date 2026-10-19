<?php

namespace App\Http\Middleware;

use App\Services\Audit\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Изменяющие запросы пользователя или приложения, после которых в журнале не появилось ни одной записи
 * (проверки, тестовые отправки, запуск обновления и т. п.), пишутся в журнал событием `request`.
 */
class AuditRequests
{
    public function __construct(private Audit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->audit->resetRequest();

        $response = $next($request);

        if (! $request->isMethodSafe() && $response->getStatusCode() < 400 && ! $this->audit->recordedInRequest() && $this->audit->actor() !== null) {
            $this->audit->record('request', changes: [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
                'input' => $request->except(['private_key', 'passphrase', 'password']),
            ]);
        }

        return $response;
    }
}
