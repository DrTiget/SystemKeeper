<?php

namespace App\Jobs;

use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Storage\StorageUploaderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class UploadFileToStorageTargets implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    /**
     * @param  list<int>  $targetIds
     */
    public function __construct(
        public string $path,
        public array $targetIds,
        public string $remotePath,
        public ?string $uploadableType = null,
        public int|string|null $uploadableId = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(StorageUploaderInterface $uploader): void
    {
        $targets = StorageTarget::query()->whereIn('id', $this->targetIds)->where('enabled', true)->get();
        $uploadable = null;
        if ($this->uploadableType !== null && $this->uploadableId !== null) {
            $class = Relation::getMorphedModel($this->uploadableType) ?? $this->uploadableType;
            $uploadable = class_exists($class) ? $class::find($this->uploadableId) : null;
        }

        $copies = $uploader->upload($this->path, $targets, $this->remotePath, $uploadable);

        $failed = array_filter($copies, fn (StorageCopy $c) => $c->status === StorageCopy::STATUS_FAILED);
        if ($failed !== []) {
            // Повтор задания пропустит уже загруженные копии и допробует только упавшие.
            throw new RuntimeException('Не удалось загрузить файл в хранилищ: '.count($failed));
        }
    }
}
