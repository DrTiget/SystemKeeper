<?php

namespace App\Services\System;

use Illuminate\Support\Facades\File;

/**
 * Связь с контейнером updater через общий каталог: запрос — request.json, ход — status.json,
 * признак жизни — heartbeat (updater обновляет его каждые 5 секунд).
 */
class Updater
{
    private const ACTIVE = ['pending', 'waiting', 'backup', 'pulling', 'restarting'];

    private function path(string $file): string
    {
        return config('systemkeeper.updater_path').'/'.$file;
    }

    public function available(): bool
    {
        $heartbeat = $this->path('heartbeat');

        return is_file($heartbeat) && time() - filemtime($heartbeat) <= config('systemkeeper.updater_heartbeat');
    }

    /** @return array{status: string, message: ?string, from: ?string, to: ?string, updated_at: ?string} */
    public function status(): array
    {
        $status = is_file($this->path('status.json'))
            ? json_decode((string) file_get_contents($this->path('status.json')), true)
            : null;

        $status = is_array($status) ? $status : ['status' => 'idle'];

        if ($this->requested() && ! in_array($status['status'] ?? null, self::ACTIVE, true)) {
            $status = ['status' => 'pending', 'message' => 'Запрос передан обновлятору', 'to' => $this->requested()];
        }

        return [
            'status' => (string) ($status['status'] ?? 'idle'),
            'message' => $status['message'] ?? null,
            'from' => ($status['from'] ?? '') ?: null,
            'to' => ($status['to'] ?? '') ?: null,
            'updated_at' => $status['updated_at'] ?? null,
        ];
    }

    public function inProgress(): bool
    {
        return in_array($this->status()['status'], self::ACTIVE, true);
    }

    public function requested(): ?string
    {
        if (! is_file($this->path('request.json'))) {
            return null;
        }

        return json_decode((string) file_get_contents($this->path('request.json')), true)['version'] ?? null;
    }

    public function request(string $version): void
    {
        File::ensureDirectoryExists(config('systemkeeper.updater_path'));
        File::put($this->path('request.json'), json_encode(['version' => $version]));
    }
}
