<?php

namespace Tests\Support;

use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Storage\StorageUploaderInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class FakeStorageUploader implements StorageUploaderInterface
{
    /** @var list<array{path: string, targets: list<int>, remote: string, owner: string|null}> */
    public array $dispatched = [];

    /** @var list<int> id удалённых копий */
    public array $deleted = [];

    /** @param list<int> $failDeleteFor id копий, удаление которых должно упасть */
    public function __construct(public array $failDeleteFor = []) {}

    public function upload(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): array
    {
        return [];
    }

    public function deleteCopy(StorageCopy $copy): void
    {
        if (in_array($copy->id, $this->failDeleteFor, true)) {
            throw new RuntimeException('хранилище недоступно');
        }
        $this->deleted[] = $copy->id;
    }

    public function dispatch(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): void
    {
        $ids = [];
        foreach ($targets as $target) {
            $ids[] = $target instanceof StorageTarget ? $target->id : (int) $target;
        }

        $this->dispatched[] = [
            'path' => $localPath,
            'targets' => $ids,
            'remote' => $remotePath,
            'owner' => $uploadable ? $uploadable->getMorphClass().'#'.$uploadable->getKey() : null,
        ];
    }
}
