<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Релизы на GitHub: последний — для обновления, список — для «Что нового». Запрос условный (ETag):
 * ответ 304 не тратит лимит API без токена (60 в час).
 */
class UpdateChecker
{
    private const CACHE_KEY = 'system.latest_release';

    public function current(): string
    {
        return (string) config('systemkeeper.version');
    }

    /**
     * @return array{release: ?array, releases: list<array>, etag: ?string, checked_at: ?string, error: ?string}
     */
    public function cached(): array
    {
        return Cache::get(self::CACHE_KEY, []) + ['release' => null, 'releases' => [], 'etag' => null, 'checked_at' => null, 'error' => null];
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
                    'If-None-Match' => $state['releases'] ? $state['etag'] : null,
                ]))
                ->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 30]);

            if ($response->status() === 304) {
                $state['error'] = null;
            } elseif ($response->successful()) {
                $releases = collect($response->json())
                    ->reject(fn ($r) => ($r['draft'] ?? false) || ! self::isRelease((string) $r['tag_name']))
                    ->map(fn ($r) => [
                        'version' => (string) $r['tag_name'],
                        'name' => (string) ($r['name'] ?: $r['tag_name']),
                        'notes' => (string) ($r['body'] ?? ''),
                        'url' => (string) $r['html_url'],
                        'published_at' => $r['published_at'] ?? null,
                    ])
                    ->sort(fn ($a, $b) => version_compare(ltrim($b['version'], 'v'), ltrim($a['version'], 'v')))
                    ->values()
                    ->all();
                $state['releases'] = $releases;
                $state['release'] = $releases[0] ?? null;
                $state['etag'] = $response->header('ETag') ?: null;
                $state['error'] = null;
            } elseif ($response->status() === 404) {
                $state = [...$state, 'release' => null, 'releases' => [], 'etag' => null, 'error' => null];
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

    /**
     * История изменений, новые версии сверху: CHANGELOG.md, вшитый в сборку, и релизы с GitHub — для версий новее
     * установленной, которых в её CHANGELOG.md ещё нет. `is_new` — версия новее установленной.
     *
     * @return list<array{version: string, name: string, notes: string, url: ?string, published_at: ?string, is_new: bool, is_current: bool}>
     */
    public function changelog(): array
    {
        $current = ltrim($this->current(), 'v');
        $release = self::isRelease($current);

        $github = collect($this->cached()['releases'])->keyBy('version');

        // описание — из CHANGELOG.md, если версия там есть; ссылка и дата — с GitHub
        return collect(self::localChangelog())
            ->keyBy('version')
            ->map(fn ($r, $v) => [...$r, 'url' => $github[$v]['url'] ?? null, 'published_at' => $github[$v]['published_at'] ?? $r['published_at']])
            ->union($github)
            ->sort(fn ($a, $b) => version_compare(ltrim($b['version'], 'v'), ltrim($a['version'], 'v')))
            ->map(fn ($r) => [
                ...$r,
                'is_new' => $release && version_compare(ltrim($r['version'], 'v'), $current, '>'),
                'is_current' => ltrim($r['version'], 'v') === $current,
            ])
            ->values()
            ->all();
    }

    /** Разделы `## vX.Y.Z — дата` из CHANGELOG.md. */
    public static function localChangelog(?string $path = null): array
    {
        $path ??= base_path('CHANGELOG.md');
        if (! is_file($path)) {
            return [];
        }

        $parts = preg_split('/^## +/m', (string) file_get_contents($path));
        array_shift($parts);

        return collect($parts)
            ->map(function (string $part) {
                [$head, $body] = array_pad(explode("\n", $part, 2), 2, '');
                if (! preg_match('/^(v\d+\.\d+\.\d+)(?:\s+—\s+(\d{4}-\d{2}-\d{2}))?/u', trim($head), $m)) {
                    return null;
                }

                return ['version' => $m[1], 'name' => "SystemKeeper {$m[1]}", 'notes' => trim($body), 'url' => null, 'published_at' => $m[2] ?? null];
            })
            ->filter()
            ->values()
            ->all();
    }
}
