<?php

namespace App\Http\Resources;

use App\Models\ServerBackupSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerBackupSettings
 */
class ServerBackupSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->enabled,
            'paths' => $this->paths,
            'excludes' => $this->excludes,
            'schedule' => $this->schedule,
            'timezone' => config('backup.timezone'),
            'next_run_at' => $this->nextRunAt()?->toIso8601String(),
            'pre_command' => $this->pre_command,
            'pre_command_timeout' => $this->pre_command_timeout,
            'pre_command_continue_on_error' => $this->pre_command_continue_on_error,
            'retention' => [
                /** Куда сохранять; пусто — в хранилище по умолчанию */
                'storage_ids' => $this->storage_ids ?? [],
                'keep_count' => $this->keep_count,
                /** Лимит объёма, байт; null — без ограничения */
                'max_size' => $this->max_size,
            ],
            'updated_at' => $this->updated_at,
        ];
    }
}
