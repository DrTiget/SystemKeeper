<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\System\UpdateChecker;
use App\Services\System\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Версия SystemKeeper и обновление в один клик. Новые релизы проверяются планировщиком раз в 10 минут;
 * само обновление выполняет контейнер updater (дамп базы, новая версия образа, откат при ошибке).
 */
class SystemController extends Controller
{
    public function __construct(private UpdateChecker $checker, private Updater $updater) {}

    /**
     * Установленная и последняя версии, ход обновления.
     *
     * `updater.available` — запущен ли контейнер updater (в разработке и ручных установках его нет);
     * `updater.status` — idle | pending | waiting | backup | pulling | restarting | done | failed | rolled_back.
     */
    public function version(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** Проверить новые релизы сейчас (только администратор). */
    public function check(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $this->checker->check();

        return response()->json(['data' => $this->payload()]);
    }

    /** Обновиться до последнего релиза (только администратор). Ход — в `GET /system/version`. */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);

        $error = match (true) {
            ! $this->checker->updateAvailable() => 'Обновлений нет.',
            ! $this->updater->available() => 'Обновлятор не запущен: обновите командой systemkeeper update на сервере.',
            $this->updater->inProgress() => 'Обновление уже идёт.',
            array_sum(Updater::busy()) > 0 => 'Идут бекапы — обновить можно будет после их окончания.',
            default => null,
        };

        if ($error) {
            return response()->json(['message' => $error, 'errors' => ['version' => [$error]]], 422);
        }

        $this->updater->request($this->checker->latest()['version']);

        return response()->json(['data' => $this->payload()], 202);
    }

    private function payload(): array
    {
        $state = $this->checker->cached();

        return [
            'current' => $this->checker->current(),
            'latest' => $state['release'],
            'update_available' => $this->checker->updateAvailable(),
            'checked_at' => $state['checked_at'],
            'check_error' => $state['error'],
            /** Идущие бекапы и синхронизации: пока они есть, обновление недоступно */
            'busy' => Updater::busy(),
            'updater' => [
                'available' => $this->updater->available(),
                ...$this->updater->status(),
            ],
        ];
    }
}
