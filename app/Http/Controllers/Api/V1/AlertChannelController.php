<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\AlertChannelRequest;
use App\Http\Resources\AlertChannelResource;
use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\ChannelSender;
use App\Services\Settings\NotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Каналы уведомлений об алертах: Telegram, почта, вебхук. Секреты наружу не отдаются.
 */
class AlertChannelController extends Controller
{
    /** Все каналы (без пагинации): `data` — массив. */
    public function index(): JsonResponse
    {
        $channels = AlertChannel::query()->withCount('rules')->orderBy('id')->get();

        return response()->json(['data' => AlertChannelResource::collection($channels)->resolve()]);
    }

    /** `422`, если канал — почта, а почта не настроена («Настройки → Уведомления»). */
    public function store(AlertChannelRequest $request): JsonResponse
    {
        $channel = AlertChannel::create($request->channelData());

        return (new AlertChannelResource($channel->loadCount('rules')))->response()->setStatusCode(201);
    }

    public function show(AlertChannel $alertChannel): AlertChannelResource
    {
        return new AlertChannelResource($alertChannel->loadCount('rules'));
    }

    /** Частичное обновление; пустой `config.bot_token` / `config.secret` оставляет прежний секрет. */
    public function update(AlertChannelRequest $request, AlertChannel $alertChannel): AlertChannelResource
    {
        $alertChannel->update($request->channelData());

        return new AlertChannelResource($alertChannel->loadCount('rules'));
    }

    /** Удалить канал: он пропадает из правил, журнал отправок в него удаляется. */
    public function destroy(AlertChannel $alertChannel): Response
    {
        $alertChannel->delete();

        return response()->noContent();
    }

    /**
     * Отправить тестовое сообщение. Всегда 200 — итог в `data.ok`, а при неудаче в `data.error`;
     * 422, если канал — почта, а почта не настроена («Настройки → Уведомления»).
     */
    public function test(AlertChannel $alertChannel, ChannelSender $sender, NotificationSettings $settings): JsonResponse
    {
        if ($alertChannel->type === AlertChannel::EMAIL && ! $settings->mailConfigured()) {
            throw ValidationException::withMessages(['type' => [NotificationSettings::NOT_CONFIGURED]]);
        }

        $error = null;
        try {
            $sender->send($alertChannel, AlertMessage::test($alertChannel->name), ['event' => 'test']);
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'Не удалось отправить';
        }

        $alertChannel->forceFill(['last_error' => $error] + ($error === null ? ['last_sent_at' => now()] : []))->save();

        return response()->json(['data' => ['ok' => $error === null, 'error' => $error]]);
    }
}
