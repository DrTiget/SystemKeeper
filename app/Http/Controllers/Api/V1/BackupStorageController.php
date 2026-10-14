<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupStorageResource;
use App\Models\BackupStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Хранилища бекапов. Пока одно — локальный диск SystemKeeper; настройки хранения (сколько копий и лимит объёма)
 * задаются у каждого сервера и базы данных.
 */
class BackupStorageController extends Controller
{
    /** Хранилища со свободным местом на диске и занятым бекапами объёмом. */
    public function index(): AnonymousResourceCollection
    {
        return BackupStorageResource::collection(BackupStorage::query()->orderBy('id')->get());
    }

    /** Хранилище и все серверы и базы данных, которые в него бекапятся (`sources`). */
    public function show(BackupStorage $storage): BackupStorageResource
    {
        return new BackupStorageResource($storage);
    }

    /** Переименовать хранилище. */
    public function update(Request $request, BackupStorage $storage): BackupStorageResource
    {
        $storage->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        return new BackupStorageResource($storage);
    }
}
