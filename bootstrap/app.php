<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only: никогда не редиректим на страницу логина
        $middleware->redirectGuestsTo(fn () => null);
        // Секреты SSH и БД передаются как есть: у ключа значимы переводы строк, у паролей — пробелы
        $middleware->trimStrings(except: ['private_key', 'passphrase', 'password']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Русские тексты стандартных ошибок API; свои сообщения (abort с текстом, 409 и т. п.) не затираются
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => 404,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                $e instanceof ValidationException => null,
                default => 500,
            };
            if ($status === null) {
                return null;
            }

            $defaults = [
                401 => 'Требуется авторизация.',
                403 => 'Доступ запрещён.',
                404 => 'Не найдено.',
                405 => 'Метод не поддерживается.',
                419 => 'Сессия истекла.',
                429 => 'Слишком много запросов. Повторите позже.',
                500 => 'Внутренняя ошибка сервера.',
                503 => 'Сервис временно недоступен.',
            ];
            // Свои русские сообщения (abort с текстом) оставляем, английские тексты фреймворка заменяем
            $own = $e instanceof HttpExceptionInterface && preg_match('/\p{Cyrillic}/u', $e->getMessage()) === 1;
            $message = match (true) {
                $own => $e->getMessage(),
                $e instanceof InvalidSignatureException => 'Ссылка недействительна или истекла.',
                default => $defaults[$status] ?? ($status >= 500 ? $defaults[500] : 'Ошибка запроса.'),
            };
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return response()->json(['message' => $message], $status, $headers);
        });
    })->create();
