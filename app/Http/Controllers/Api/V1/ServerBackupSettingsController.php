<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\UpdateBackupSettingsRequest;
use App\Http\Resources\ServerBackupSettingsResource;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/**
 * Настройки бекапа сервера (одни на сервер). Пока настройки не сохранены, отдаются значения по умолчанию.
 */
class ServerBackupSettingsController extends Controller
{
    public function show(Server $server): ServerBackupSettingsResource
    {
        return new ServerBackupSettingsResource($server->backupSettingsOrDefault());
    }

    /**
     * Частичное обновление: передаются только меняемые поля.
     */
    public function update(UpdateBackupSettingsRequest $request, Server $server): JsonResponse
    {
        $settings = $server->backupSettingsOrDefault();
        $settings->fill($request->settingsData())->save();

        // 200 и при первом сохранении: для клиента это изменение настроек, а не создание ресурса
        return (new ServerBackupSettingsResource($settings))->response()->setStatusCode(200);
    }
}
