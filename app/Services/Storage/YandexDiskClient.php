<?php

namespace App\Services\Storage;

use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Клиент REST API Яндекс Диска (cloud-api.yandex.net) с OAuth-токеном. Запросы с 429/5xx и сетевыми
 * сбоями повторяются с нарастающей паузой. Файлы передаются потоком, токен нигде не логируется.
 * Пути — полные: `disk:/SystemKeeper/servers/5/…` (или `app:/…` для токена с доступом только к папке приложения).
 */
class YandexDiskClient
{
    /** @var array<string, true> папки, которые уже точно есть */
    private array $directories = [];

    public function __construct(private readonly string $token) {}

    /** @return array{total: int, used: int, free: int, login: string|null} */
    public function quota(): array
    {
        $data = $this->send(fn () => $this->api()->get('/'))->json();
        $total = (int) ($data['total_space'] ?? 0);
        $used = (int) ($data['used_space'] ?? 0);

        return ['total' => $total, 'used' => $used, 'free' => max($total - $used, 0), 'login' => $data['user']['login'] ?? null];
    }

    /** Метаданные ресурса; null — если его нет. */
    public function info(string $path, string $fields = 'type,size,modified,mime_type'): ?array
    {
        $response = $this->send(fn () => $this->api()->get('/resources', ['path' => $path, 'fields' => $fields, 'limit' => 0]), [404]);

        return $response->status() === 404 ? null : $response->json();
    }

    /**
     * Содержимое папки (без вложенных), постранично.
     *
     * @return \Generator<array{type: string, path: string, size?: int, modified?: string}>
     */
    public function list(string $path): \Generator
    {
        for ($offset = 0; ; $offset += 1000) {
            $response = $this->send(fn () => $this->api()->get('/resources', [
                'path' => $path, 'limit' => 1000, 'offset' => $offset, 'fields' => '_embedded.items.type,_embedded.items.path,_embedded.items.size,_embedded.items.modified,_embedded.total',
            ]), [404]);
            if ($response->status() === 404) {
                return;
            }
            $items = $response->json('_embedded.items') ?? [];
            yield from $items;
            if (count($items) < 1000) {
                return;
            }
        }
    }

    /** Создаёт папку и всех недостающих родителей. */
    public function mkdir(string $path): void
    {
        [$scheme, $rest] = self::split($path);
        $current = $scheme;
        foreach (array_filter(explode('/', $rest), fn ($p) => $p !== '') as $segment) {
            $current .= '/'.$segment;
            if (isset($this->directories[$current])) {
                continue;
            }
            $response = $this->send(fn () => $this->api()->send('PUT', '/resources?'.http_build_query(['path' => $current])), [409]);
            if ($response->status() === 409 && $response->json('error') !== 'DiskPathPointsToExistentDirectoryError') {
                throw new YandexDiskException($this->describe($response));
            }
            $this->directories[$current] = true;
        }
    }

    /**
     * Загружает поток в файл (перезаписывая). Поток без известного размера сначала копируется во временный файл —
     * загрузчику Диска нужен Content-Length. После загрузки размер сверяется с метаданными.
     *
     * @param  resource  $stream
     */
    public function upload(string $path, $stream): void
    {
        $size = fstat($stream)['size'] ?? null;
        $seekable = (bool) (stream_get_meta_data($stream)['seekable'] ?? false);
        if (! $size || ! $seekable) {
            $spool = fopen('php://temp/maxmemory:'.(8 * 1024 * 1024), 'w+b');
            $size = stream_copy_to_stream($stream, $spool);
            rewind($spool);
            $stream = $spool;
        }
        $start = ftell($stream);

        $this->mkdir(dirname($path));
        $href = $this->send(fn () => $this->api()->get('/resources/upload', ['path' => $path, 'overwrite' => 'true']))->json('href')
            ?: throw new YandexDiskException('Яндекс Диск не вернул ссылку для загрузки.');

        // Один PSR-поток на все попытки; в конце отвязываем его, не закрывая: потоком владеет вызывающий
        $body = Utils::streamFor($stream, ['size' => $size]);
        try {
            $this->send(function () use ($href, $body, $start, $size) {
                $body->seek($start);

                return Http::timeout((int) config('yandex_disk.transfer_timeout'))
                    ->withHeaders(['Content-Length' => (string) $size])
                    ->withBody($body, 'application/octet-stream')
                    ->put($href);
            });
        } finally {
            $body->detach();
        }

        $actual = $this->info($path, 'size')['size'] ?? null;
        if ($actual !== null && (int) $actual !== (int) $size) {
            throw new YandexDiskException("Размер на Диске ({$actual}) не совпал с отправленным ({$size}).");
        }
    }

    /** @return resource поток для чтения файла */
    public function download(string $path)
    {
        $href = $this->send(fn () => $this->api()->get('/resources/download', ['path' => $path]))->json('href')
            ?: throw new YandexDiskException('Яндекс Диск не вернул ссылку для скачивания.');

        $response = $this->send(fn () => Http::timeout((int) config('yandex_disk.transfer_timeout'))->withOptions(['stream' => true])->get($href));

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
    }

    public function delete(string $path): void
    {
        // большие папки удаляются асинхронно (202) — ждать не нужно
        $this->send(fn () => $this->api()->send('DELETE', '/resources?'.http_build_query(['path' => $path, 'permanently' => 'true'])), [404]);
        unset($this->directories[$path]);
    }

    public function copy(string $from, string $to, bool $move = false): void
    {
        $this->mkdir(dirname($to));
        $this->send(fn () => $this->api()->send('POST', '/resources/'.($move ? 'move' : 'copy').'?'.http_build_query(['from' => $from, 'path' => $to, 'overwrite' => 'true'])));
    }

    /** @return array{string, string} схема (`disk:` / `app:`) и путь */
    private static function split(string $path): array
    {
        return preg_match('#^([a-z]+:)(/.*)?$#', $path, $m) ? [$m[1], $m[2] ?? '/'] : ['disk:', $path];
    }

    /** Запросы без тела: Диск отвечает 400 «No payload expected», если прислать хотя бы `[]` (как делают put/post/delete Laravel). */
    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config('yandex_disk.base_url'))
            ->withHeaders(['Authorization' => 'OAuth '.$this->token])
            ->acceptJson()
            ->timeout((int) config('yandex_disk.timeout'));
    }

    /**
     * Запрос с повторами на 429/5xx и сетевые ошибки. Статусы из $tolerate возвращаются как есть, остальные ошибки — исключение.
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
                    throw new YandexDiskException('Нет соединения с Яндекс Диском: '.$e->getMessage(), 0, $e);
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

            throw new YandexDiskException($this->describe($response), $response->status());
        }
    }

    private function backoff(int $attempt): void
    {
        usleep((int) config('yandex_disk.backoff_ms') * 1000 * (2 ** ($attempt - 1)));
    }

    private function describe(Response $response): string
    {
        $message = match ($response->status()) {
            401 => 'Токен Яндекс Диска недействителен или отозван (401)',
            403 => 'Нет доступа к Яндекс Диску (403) — проверьте права токена',
            404 => 'Не найдено на Яндекс Диске (404)',
            409 => 'Конфликт пути на Яндекс Диске (409)',
            429 => 'Слишком много запросов к Яндекс Диску (429)',
            507 => 'На Яндекс Диске недостаточно места (507)',
            default => 'Ошибка Яндекс Диска ('.$response->status().')',
        };
        $detail = $response->json('description') ?? $response->json('message');

        return is_string($detail) && $detail !== '' ? "{$message}: {$detail}" : $message;
    }
}
