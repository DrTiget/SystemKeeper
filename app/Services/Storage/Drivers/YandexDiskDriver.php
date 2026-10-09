<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageTarget;
use App\Services\Storage\YandexDiskClient;

class YandexDiskDriver implements StorageDriver
{
    private readonly YandexDiskClient $client;

    public function __construct(StorageTarget $target)
    {
        $this->client = new YandexDiskClient((string) $target->token);
    }

    public function upload(string $localPath, string $remotePath): array
    {
        return ['md5' => $this->client->upload($localPath, $remotePath), 'sha256' => null];
    }

    public function delete(string $remotePath): void
    {
        $this->client->delete($remotePath);
    }

    public function check(): array
    {
        return $this->client->quota();
    }
}
