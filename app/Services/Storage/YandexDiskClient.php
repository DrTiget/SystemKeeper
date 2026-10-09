<?php

namespace App\Services\Storage;

use App\Exceptions\Storage\StorageException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Клиент REST API Яндекс.Диска (cloud-api.yandex.net). Запросы с 429/5xx и сетевыми
 * сбоями повторяются с экспоненциальным backoff. Токен нигде не логируется.
 */
class YandexDiskClient
{
    public function __construct(private readonly string $token) {}

    /** @return array{total_space: int, used_space: int, free_space: int} */
    public function quota(): array
    {
        $data = $this->send(fn () => $this->api()->get('/'))->json();
        $total = (int) ($data['total_space'] ?? 0);
        $used = (int) ($data['used_space'] ?? 0);

        return ['total_space' => $total, 'used_space' => $used, 'free_space' => max($total - $used, 0)];
    }

    /** Создаёт папку и всех недостающих родителей. */
    public function ensureDirectory(string $path): void
    {
        $current = '';
        foreach (array_filter(explode('/', $path), fn ($p) => $p !== '') as $segment) {
            $current .= '/'.$segment;
            $response = $this->send(fn () => $this->api()->put('/resources?'.http_build_query(['path' => $current])), [409]);
            // 409 — папка уже существует (или конфликт, который вскроется при загрузке).
            if ($response->status() === 409 && ($response->json('error') ?? '') !== 'DiskPathPointsToExistentDirectoryError') {
                throw new StorageException($this->describe($response), 409);
            }
        }
    }

    /**
     * Потоково загружает локальный файл (в память целиком не читается) и сверяет md5
     * с метаданными ресурса. Возвращает md5.
     */
    public function upload(string $localPath, string $remotePath): string
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new StorageException("Файл не найден или недоступен: {$localPath}");
        }

        $expected = md5_file($localPath);
        $this->ensureDirectory(dirname($remotePath));

        $href = $this->send(fn () => $this->api()->get('/resources/upload', ['path' => $remotePath, 'overwrite' => 'true']))->json('href');
        if (! $href) {
            throw new StorageException('Яндекс.Диск не вернул ссылку на загрузку.');
        }

        // Поток открывается заново на каждую попытку.
        $this->send(function () use ($href, $localPath) {
            $handle = fopen($localPath, 'rb');

            try {
                return Http::timeout((int) config('yandex_disk.upload_timeout'))
                    ->withBody(Utils::streamFor($handle), 'application/octet-stream')
                    ->put($href);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        });

        $actual = $this->remoteMd5($remotePath);
        if ($actual !== $expected) {
            throw new StorageException("Контрольная сумма не совпала: локально {$expected}, на Диске ".($actual ?? 'неизвестна').'.');
        }

        return $expected;
    }

    /** md5 ресурса по метаданным; null, если Диск его пока не посчитал. */
    public function remoteMd5(string $remotePath): ?string
    {
        $attempts = max((int) config('yandex_disk.verify_attempts'), 1);

        for ($i = 1; $i <= $attempts; $i++) {
            $response = $this->send(fn () => $this->api()->get('/resources', ['path' => $remotePath, 'fields' => 'md5,size']), [404]);
            $md5 = $response->successful() ? $response->json('md5') : null;
            if ($md5) {
                return strtolower($md5);
            }
            if ($i < $attempts) {
                $this->backoff($i);
            }
        }

        return null;
    }

    public function delete(string $remotePath, bool $permanently = true): void
    {
        $this->send(fn () => $this->api()->delete('/resources?'.http_build_query(['path' => $remotePath, 'permanently' => $permanently ? 'true' : 'false'])), [404]);
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config('yandex_disk.base_url'))
            ->withHeaders(['Authorization' => 'OAuth '.$this->token])
            ->acceptJson()
            ->timeout((int) config('yandex_disk.timeout'));
    }

    /**
     * Выполняет запрос с ретраями на 429/5xx и сетевые ошибки. Статусы из $tolerate
     * возвращаются вызывающему как есть, остальные 4xx — исключение.
     *
     * @param  callable(): Response  $request
     * @param  list<int>  $tolerate
     */
    private function send(callable $request, array $tolerate = []): Response
    {
        $attempts = max((int) config('yandex_disk.attempts'), 1);

        for ($i = 1; ; $i++) {
            try {
                $response = $request();
            } catch (ConnectionException $e) {
                if ($i >= $attempts) {
                    throw new StorageException('Нет соединения с Яндекс.Диском: '.$e->getMessage());
                }
                $this->backoff($i);

                continue;
            }

            if ($response->successful() || in_array($response->status(), $tolerate, true)) {
                return $response;
            }

            if (($response->status() === 429 || $response->serverError()) && $i < $attempts) {
                $this->backoff($i);

                continue;
            }

            throw new StorageException($this->describe($response), $response->status());
        }
    }

    private function backoff(int $attempt): void
    {
        usleep((int) config('yandex_disk.backoff_ms') * 1000 * (2 ** ($attempt - 1)));
    }

    private function describe(Response $response): string
    {
        $message = match ($response->status()) {
            401 => 'Токен недействителен или отозван (401)',
            403 => 'Доступ запрещён (403)',
            404 => 'Ресурс не найден (404)',
            429 => 'Превышен лимит запросов (429)',
            507 => 'На Диске недостаточно места (507)',
            default => 'Ошибка Яндекс.Диска ('.$response->status().')',
        };

        $detail = $response->json('description') ?? $response->json('message');

        return is_string($detail) && $detail !== '' ? "{$message}: {$detail}" : $message;
    }
}
