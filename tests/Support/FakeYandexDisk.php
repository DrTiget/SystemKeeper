<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Яндекс Диск в памяти для Http::fake: папки, загрузка и скачивание по выданным ссылкам, удаление, квота.
 */
class FakeYandexDisk
{
    /** @var array<string, string> путь → содержимое */
    public array $files = [];

    /** @var array<string, true> */
    public array $dirs = ['disk:' => true, 'disk:/' => true];

    public ?string $token = null;

    public int $total = 10 * 1024 ** 3;

    /** Ответ API на любой запрос (например, 401 — отозванный токен) */
    public ?array $failWith = null;

    public function install(): self
    {
        Http::fake(fn (Request $r) => $this->handle($r));

        return $this;
    }

    private function json(array $data, int $status = 200): PromiseInterface
    {
        return Http::response($data, $status);
    }

    private function handle(Request $r): PromiseInterface
    {
        $url = parse_url($r->url());
        parse_str($url['query'] ?? '', $q);

        if ($url['host'] === 'uploader.test') {
            $this->files[$q['path']] = $r->body();

            return Http::response('', 201);
        }
        if ($url['host'] === 'downloader.test') {
            return isset($this->files[$q['path']]) ? Http::response($this->files[$q['path']]) : Http::response('', 404);
        }

        $this->token = $r->header('Authorization')[0] ?? null;
        // как настоящий Диск: у запросов к API, кроме загрузки по ссылке, тела быть не должно
        if (in_array($r->method(), ['PUT', 'POST', 'DELETE'], true) && $r->body() !== '') {
            return $this->json(['message' => 'No payload expected.', 'error' => 'BadRequest'], 400);
        }
        if ($this->failWith) {
            return $this->json($this->failWith[1], $this->failWith[0]);
        }
        $path = $q['path'] ?? null;
        $resource = substr($url['path'], strlen('/v1/disk'));

        return match (true) {
            $resource === '/' || $resource === '' => $this->json(['total_space' => $this->total, 'used_space' => array_sum(array_map('strlen', $this->files)), 'user' => ['login' => 'tester']]),
            $resource === '/resources/upload' => isset($this->dirs[dirname($path)]) ? $this->json(['href' => 'https://uploader.test/put?'.http_build_query(['path' => $path])]) : $this->json(['error' => 'DiskPathDoesntExistsError'], 409),
            $resource === '/resources/download' => isset($this->files[$path]) ? $this->json(['href' => 'https://downloader.test/get?'.http_build_query(['path' => $path])]) : $this->json(['error' => 'DiskNotFoundError'], 404),
            $resource === '/resources' && $r->method() === 'PUT' => $this->mkdir($path),
            $resource === '/resources' && $r->method() === 'DELETE' => $this->delete($path),
            $resource === '/resources' => $this->info($path),
            default => $this->json(['error' => 'NotImplemented'], 500),
        };
    }

    private function mkdir(string $path): PromiseInterface
    {
        if (isset($this->dirs[$path])) {
            return $this->json(['error' => 'DiskPathPointsToExistentDirectoryError'], 409);
        }
        if (! isset($this->dirs[dirname($path)])) {
            return $this->json(['error' => 'DiskPathDoesntExistsError'], 409);
        }
        $this->dirs[$path] = true;

        return $this->json(['href' => ''], 201);
    }

    private function delete(string $path): PromiseInterface
    {
        $found = isset($this->files[$path]) || isset($this->dirs[$path]);
        unset($this->files[$path], $this->dirs[$path]);
        foreach (array_keys($this->files + $this->dirs) as $p) {
            if (str_starts_with($p, $path.'/')) {
                unset($this->files[$p], $this->dirs[$p]);
            }
        }

        return $found ? Http::response('', 204) : $this->json(['error' => 'DiskNotFoundError'], 404);
    }

    private function info(string $path): PromiseInterface
    {
        if (isset($this->files[$path])) {
            return $this->json(['type' => 'file', 'path' => $path, 'size' => strlen($this->files[$path]), 'modified' => '2026-10-15T10:00:00+00:00']);
        }
        if (! isset($this->dirs[$path])) {
            return $this->json(['error' => 'DiskNotFoundError'], 404);
        }
        $items = [];
        foreach ($this->dirs as $d => $_) {
            if (dirname($d) === $path && $d !== $path) {
                $items[] = ['type' => 'dir', 'path' => $d];
            }
        }
        foreach ($this->files as $f => $c) {
            if (dirname($f) === $path) {
                $items[] = ['type' => 'file', 'path' => $f, 'size' => strlen($c)];
            }
        }

        return $this->json(['type' => 'dir', 'path' => $path, '_embedded' => ['items' => $items, 'total' => count($items)]]);
    }
}
