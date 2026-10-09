<?php

namespace App\Support;

use App\Http\Requests\StorageTarget\StoreStorageTargetRequest;
use App\Http\Requests\StorageTarget\UpdateStorageTargetRequest;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Правила запросов хранилищ зависят от type, а Scramble читает rules() без входных данных —
 * поэтому поля всех типов дописываются в схемы вручную.
 */
class StorageTargetOpenApi
{
    public function __invoke(OpenApi $openApi): void
    {
        $fields = [
            'base_path' => [new StringType, 'local, yandex_disk, sftp: абсолютный путь (для sftp — на сервере)'],
            'token' => [new StringType, 'yandex_disk: OAuth-токен (при изменении необязателен; наружу не отдаётся)'],
            'server_id' => [new IntegerType, 'sftp: id существующего сервера'],
            'endpoint' => [new StringType, 's3: URL S3-совместимого хранилища (пусто — AWS)'],
            'region' => [new StringType, 's3: регион'],
            'bucket' => [new StringType, 's3: бакет'],
            'prefix' => [new StringType, 's3: папка внутри бакета'],
            'access_key' => [new StringType, 's3: access key'],
            'secret_key' => [new StringType, 's3: secret key (при изменении необязателен; наружу не отдаётся)'],
            'path_style' => [new BooleanType, 's3: path-style адресация (MinIO и др.)'],
            'storage_class' => [new StringType, 's3: класс хранения, например STANDARD_IA'],
        ];

        foreach ([StoreStorageTargetRequest::class, UpdateStorageTargetRequest::class] as $name) {
            $schema = $openApi->components->schemas[$name] ?? null;
            if ($schema === null) {
                continue;
            }
            foreach ($fields as $field => [$type, $description]) {
                $schema->type->addProperty($field, (clone $type)->setDescription($description));
            }
        }
    }
}
