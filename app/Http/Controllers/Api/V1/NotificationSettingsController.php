<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationSettingsRequest;
use App\Http\Requests\Settings\TestMailRequest;
use App\Mail\TestMail;
use App\Services\Settings\NotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Настройки уведомлений: почта (SMTP) и подключение к Telegram Bot API (адрес и прокси). Только администратор
 * (gate manage-users). Пароли наружу не отдаются — только признаки `has_password`.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(private NotificationSettings $settings) {}

    /**
     * Текущие настройки.
     *
     * `mail.source`: `settings` — SMTP из этих настроек, `env` — из .env сервера, `none` — не настроена (`configured: false`,
     * письма никуда не уходят). `telegram.api_url` без настройки — `https://api.telegram.org`.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Сохранить настройки (разделы и поля можно слать частично) → то же, что `GET`.
     *
     * Пустой или отсутствующий пароль (`mail.password`, `telegram.proxy.password`) оставляет прежний;
     * `clear_password: true` в том же разделе стирает. Пустой `mail.host` отключает SMTP из настроек (почта — из .env).
     */
    public function update(NotificationSettingsRequest $request): JsonResponse
    {
        $this->settings->save($request->merged());

        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Отправить тестовое письмо на адрес. Всегда `200` — итог в `data.ok`, при неудаче текст в `data.error`;
     * `422`, если почта не настроена.
     */
    public function testMail(TestMailRequest $request): JsonResponse
    {
        if (! $this->settings->mailConfigured()) {
            throw ValidationException::withMessages(['to' => [NotificationSettings::NOT_CONFIGURED]]);
        }

        $error = null;
        try {
            $this->settings->sendMail($request->validated('to'), new TestMail);
        } catch (Throwable $e) {
            $error = $this->settings->scrub($e->getMessage()) ?: 'Не удалось отправить';
        }

        return response()->json(['data' => ['ok' => $error === null, 'error' => $error]]);
    }

    /**
     * Проверить доступность адреса Bot API (через прокси, если он включён): любой HTTP-ответ — успех.
     * Всегда `200` — итог в `data.ok` и `data.error`.
     */
    public function testTelegram(): JsonResponse
    {
        return response()->json(['data' => $this->settings->checkTelegram()]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $all = $this->settings->all();
        $mail = $all['mail'];
        $proxy = $all['telegram']['proxy'];
        $source = $this->settings->mailSource();

        return [
            'mail' => [
                'configured' => $source !== 'none',
                'host' => $mail['host'],
                'port' => (int) $mail['port'],
                'encryption' => $mail['encryption'],
                'username' => $mail['username'],
                'has_password' => filled($mail['password']),
                'from_address' => $mail['from_address'],
                'from_name' => $mail['from_name'],
                'source' => $source,
            ],
            'telegram' => [
                'api_url' => $all['telegram']['api_url'] ?: NotificationSettings::TELEGRAM_API,
                'proxy' => [
                    'enabled' => (bool) $proxy['enabled'],
                    'type' => $proxy['type'],
                    'host' => $proxy['host'],
                    'port' => $proxy['port'] === null ? null : (int) $proxy['port'],
                    'username' => $proxy['username'],
                    'has_password' => filled($proxy['password']),
                ],
            ],
        ];
    }
}
