<?php

namespace Tests\Support;

use App\Models\StorageTarget;
use App\Services\Storage\Drivers\StorageDriver;
use App\Services\Storage\Drivers\StorageDriverFactory;
use RuntimeException;

/** Фабрика, отдающая запоминающий драйвер вместо реального хранилища. */
class FakeStorageDriverFactory extends StorageDriverFactory
{
    /** @var list<array{op: string, target: int, path: string}> */
    public array $calls = [];

    /** @var list<int> id целей, у которых delete() падает */
    public array $failDeleteFor = [];

    public function make(StorageTarget $target): StorageDriver
    {
        $factory = $this;

        return new class($factory, $target) implements StorageDriver
        {
            public function __construct(private FakeStorageDriverFactory $f, private StorageTarget $t) {}

            public function upload(string $localPath, string $remotePath): array
            {
                $this->f->calls[] = ['op' => 'upload', 'target' => $this->t->id, 'path' => $remotePath];

                return $this->t->type === StorageTarget::TYPE_SFTP
                    ? ['md5' => null, 'sha256' => hash_file('sha256', $localPath)]
                    : ['md5' => md5_file($localPath), 'sha256' => null];
            }

            public function delete(string $remotePath): void
            {
                if (in_array($this->t->id, $this->f->failDeleteFor, true)) {
                    throw new RuntimeException('storage down');
                }
                $this->f->calls[] = ['op' => 'delete', 'target' => $this->t->id, 'path' => $remotePath];
            }

            public function check(): array
            {
                return ['total_space' => null, 'used_space' => null, 'free_space' => null];
            }
        };
    }
}
