<?php

namespace App\Services\Storage\Drivers;

use App\Exceptions\Storage\StorageException;
use App\Models\StorageTarget;
use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Throwable;

/**
 * S3 и S3-совместимые хранилища (AWS, Yandex Object Storage, MinIO…). Файлы больше порога уходят
 * потоковым multipart (в памяти держатся только несколько частей), остальные — одним PutObject.
 * Целостность: размер и ETag объекта сверяются с вычисленными локально; ретраи с backoff — в SDK.
 */
class S3Driver implements StorageDriver
{
    private const MIN_PART_SIZE = 5 * 1024 * 1024;

    private const MAX_PARTS = 10000;

    private ?S3ClientInterface $client;

    public function __construct(private readonly StorageTarget $target, ?S3ClientInterface $client = null)
    {
        $this->client = $client;
    }

    public function upload(string $localPath, string $remotePath): array
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new StorageException("Файл не найден или недоступен: {$localPath}");
        }

        $size = (int) filesize($localPath);
        $key = $this->key($remotePath);
        $threshold = max((int) config('storage_targets.s3.multipart_threshold'), self::MIN_PART_SIZE);
        $multipart = $size >= $threshold;
        $partSize = $multipart ? $this->partSize($size) : 0;
        $storageClass = $this->target->setting('storage_class');

        try {
            if ($multipart) {
                $this->uploadMultipart($localPath, $key, $partSize, $storageClass);
            } else {
                $this->client()->putObject(array_filter([
                    'Bucket' => $this->bucket(),
                    'Key' => $key,
                    'SourceFile' => $localPath,
                    'StorageClass' => $storageClass,
                ]));
            }

            $head = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (StorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }

        [$md5, $expectedEtag] = $this->localDigest($localPath, $multipart ? $partSize : null);

        $remoteSize = (int) ($head['ContentLength'] ?? -1);
        if ($remoteSize !== $size) {
            $this->deleteQuietly($key);
            throw new StorageException("Размер объекта в хранилище ({$remoteSize}) не совпал с исходным ({$size}).");
        }

        $etag = strtolower(trim((string) ($head['ETag'] ?? ''), '"'));
        // ETag бывает нестандартным (шифрование SSE-KMS/SSE-C, некоторые совместимые хранилища) — тогда доверяем сверке размера
        if ($this->isPlainEtag($etag) && $etag !== $expectedEtag) {
            $this->deleteQuietly($key);
            throw new StorageException("Контрольная сумма (ETag) не совпала: локально {$expectedEtag}, в хранилище {$etag}.");
        }

        return ['md5' => $md5, 'sha256' => null];
    }

    public function delete(string $remotePath): void
    {
        $key = $this->key($remotePath);
        $prefix = ltrim(rtrim($this->target->base_path, '/'), '/');
        if (($prefix !== '' && ! str_starts_with($key, $prefix.'/')) || preg_match('#(^|/)\.\.(/|$)#', $key)) {
            throw new StorageException("Ключ {$key} вне префикса хранилища.");
        }

        try {
            // DeleteObject в S3 идемпотентен: отсутствие объекта — не ошибка
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function check(): array
    {
        try {
            $this->client()->headBucket(['Bucket' => $this->bucket()]);

            $prefix = ltrim(rtrim($this->target->base_path, '/'), '/');
            $key = ($prefix === '' ? '' : $prefix.'/').'.systemkeeper-check-'.bin2hex(random_bytes(6));
            $this->client()->putObject(['Bucket' => $this->bucket(), 'Key' => $key, 'Body' => 'ok']);
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }

        // S3 не сообщает свободное место
        return ['total_space' => null, 'used_space' => null, 'free_space' => null];
    }

    private function uploadMultipart(string $localPath, string $key, int $partSize, ?string $storageClass): void
    {
        $uploader = new MultipartUploader($this->client(), $localPath, [
            'bucket' => $this->bucket(),
            'key' => $key,
            'part_size' => $partSize,
            'concurrency' => 2,
            'before_initiate' => function ($command) use ($storageClass) {
                if ($storageClass) {
                    $command['StorageClass'] = $storageClass;
                }
            },
        ]);

        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            // Недогруженные части занимают место и тарифицируются — убираем
            try {
                $state = $e->getState();
                $id = $state->getId();
                if (! empty($id['UploadId'])) {
                    $this->client()->abortMultipartUpload($id);
                }
            } catch (Throwable) {
                // основная ошибка важнее
            }

            throw $this->wrap($e);
        }
    }

    /** Размер части: не меньше настройки и такой, чтобы уложиться в лимит частей S3. */
    private function partSize(int $size): int
    {
        $size = max($size, 1);

        return max((int) config('storage_targets.s3.part_size'), self::MIN_PART_SIZE, (int) ceil($size / (self::MAX_PARTS - 1)));
    }

    /**
     * md5 файла и ETag, который S3 присвоит объекту: md5 для одиночной загрузки, md5(md5 частей)-N для multipart.
     *
     * @return array{0: string, 1: string}
     */
    private function localDigest(string $path, ?int $partSize): array
    {
        $whole = hash_init('md5');
        $handle = fopen($path, 'rb');
        $partHashes = '';
        $parts = 0;

        try {
            if ($partSize === null) {
                while (! feof($handle)) {
                    hash_update($whole, (string) fread($handle, 1048576));
                }
            } else {
                $part = null;
                $inPart = 0;
                while (! feof($handle)) {
                    $chunk = (string) fread($handle, min(1048576, $partSize));
                    if ($chunk === '') {
                        continue;
                    }
                    hash_update($whole, $chunk);
                    // Чанк может пересекать границу части — режем
                    while ($chunk !== '') {
                        $part ??= hash_init('md5');
                        $take = min(strlen($chunk), $partSize - $inPart);
                        hash_update($part, substr($chunk, 0, $take));
                        $inPart += $take;
                        $chunk = (string) substr($chunk, $take);
                        if ($inPart === $partSize) {
                            $partHashes .= hash_final($part, true);
                            $parts++;
                            $part = null;
                            $inPart = 0;
                        }
                    }
                }
                if ($part !== null) {
                    $partHashes .= hash_final($part, true);
                    $parts++;
                }
            }
        } finally {
            fclose($handle);
        }

        $md5 = hash_final($whole);

        return [$md5, $partSize === null ? $md5 : md5($partHashes).'-'.$parts];
    }

    private function isPlainEtag(string $etag): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}(-\d+)?$/', $etag);
    }

    private function deleteQuietly(string $key): void
    {
        try {
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (Throwable) {
            // не удалось — сообщаем об исходной проблеме
        }
    }

    private function bucket(): string
    {
        return (string) $this->target->setting('bucket');
    }

    /** Ключ объекта = полный путь без ведущего «/». */
    private function key(string $fullPath): string
    {
        return ltrim($fullPath, '/');
    }

    private function client(): S3ClientInterface
    {
        return $this->client ??= new S3Client(array_filter([
            'version' => 'latest',
            'region' => $this->target->setting('region') ?: 'us-east-1',
            'endpoint' => $this->target->setting('endpoint') ?: null,
            'use_path_style_endpoint' => (bool) $this->target->setting('path_style', false),
            'credentials' => [
                'key' => (string) $this->target->setting('access_key'),
                'secret' => (string) $this->target->secret,
            ],
            'retries' => ['mode' => 'standard', 'max_attempts' => max((int) config('storage_targets.s3.attempts'), 1)],
            'http' => [
                'connect_timeout' => (int) config('storage_targets.s3.connect_timeout'),
                'timeout' => (int) config('storage_targets.s3.timeout'),
            ],
            // Дополнительные CRC-трейлеры SDK ≥3.337 не понимают многие S3-совместимые хранилища
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ], fn ($v) => $v !== null));
    }

    private function wrap(Throwable $e): StorageException
    {
        if ($e instanceof AwsException) {
            $code = $e->getAwsErrorCode();
            $status = $e->getStatusCode();
            $message = match (true) {
                in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch'], true) => 'Неверный access key или secret key',
                $code === 'AccessDenied' || $status === 403 => 'Доступ запрещён',
                $code === 'NoSuchBucket' => 'Бакет не найден',
                $status === 404 => 'Бакет или объект не найден',
                default => 'Ошибка S3',
            };
            $detail = $code ?: ($e->getAwsErrorMessage() ?: null);

            return new StorageException($message.($detail ? " ({$detail})" : '').($status ? " [HTTP {$status}]" : ''), $status);
        }

        // Сообщения SDK могут содержать подробности запроса, но не секретный ключ; режем длину
        return new StorageException('Ошибка S3: '.mb_substr(strtok($e->getMessage(), "\n"), 0, 500));
    }
}
