<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Последний релиз на GitHub. Запрос условный (ETag): ответ 304 не тратит лимит API без токена (60 в час).
 */
class UpdateChecker
{
    private const CACHE_KEY = 'system.latest_release';

    public function current(): string
    {
        return (string) config('systemkeeper.version');
    }

    /**
     * @return array{release: ?array, etag: ?string, checked_at: ?string, error: ?string}
     */
    public function cached(): array
    {
        return Cache::get(self::CACHE_KEY, ['release' => null, 'etag' => null, 'checked_at' => null, 'error' => null]);
    }

    public function latest(): ?array
    {
        return $this->cached()['release'];
    }

    public function check(): array
    {
        $state = $this->cached();
        $repo = config('systemkeeper.repository');

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_filter([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'SystemKeeper/'.$this->current(),
                    'If-None-Match' => $state['release'] ? $state['etag'] : null,
                ]))
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if ($response->status() === 304) {
                $state['error'] = null;
            } elseif ($response->successful()) {
                $data = $response->json();
                $state['release'] = [
                    'version' => (string) $data['tag_name'],
                    'name' => (string) ($data['name'] ?: $data['tag_name']),
                    'notes' => (string) ($data['body'] ?? ''),
                    'url' => (string) $data['html_url'],
                    'published_at' => $data['published_at'] ?? null,
                ];
                $state['etag'] = $response->header('ETag') ?: null;
                $state['error'] = null;
            } elseif ($response->status() === 404) {
                $state = [...$state, 'release' => null, 'etag' => null, 'error' => null];
            } else {
                $state['error'] = "GitHub ответил {$response->status()}";
            }
        } catch (Throwable $e) {
            $state['error'] = 'Не удалось связаться с GitHub: '.$e->getMessage();
        }

        $state['checked_at'] = now()->toIso8601String();
        Cache::forever(self::CACHE_KEY, $state);

        return $state;
    }

    /** Есть ли релиз новее установленной версии; сборка dev не обновляется. */
    public function updateAvailable(): bool
    {
        $latest = $this->latest();

        return $latest !== null
            && self::isRelease($this->current())
            && version_compare(ltrim($latest['version'], 'v'), ltrim($this->current(), 'v'), '>');
    }

    public static function isRelease(string $version): bool
    {
        return (bool) preg_match('/^v?\d+\.\d+\.\d+$/', $version);
    }
}
