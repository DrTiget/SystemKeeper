<?php

namespace App\Services\Backup;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Services\Database\DatabaseClient;
use App\Services\Database\DatabaseCommandRunner;
use App\Services\Database\DatabaseException;

/**
 * Бекап баз данных: по файлу на базу (mysqldump / pg_dump), потоком с сервера или напрямую.
 */
class DatabaseBackupRunner
{
    public function __construct(private DatabaseClient $client, private DatabaseCommandRunner $runner) {}

    public function run(DatabaseConnection $c, Backup $backup): void
    {
        // Список баз с размерами: нужен для «все базы» и для истории; при явном списке ошибка не критична
        try {
            $sizes = array_column($this->client->databases($c), 'size', 'name');
        } catch (DatabaseException|SshConnectionException $e) {
            if (! $c->databases) {
                throw $e;
            }
            $sizes = [];
        }
        $databases = $c->databases ?: array_values(array_filter(
            array_keys($sizes),
            fn ($name) => ! in_array($name, DatabaseConnection::SYSTEM_DATABASES[$c->engine], true),
        ));
        if ($databases === []) {
            throw new BackupException('Нет баз для бекапа.');
        }

        $options = $c->resolvedOptions();
        $backup->putMeta('source', [
            'type' => 'database',
            'name' => $c->name,
            'engine' => $c->engine,
            'version' => $this->version($c),
            'address' => "{$c->username}@{$c->host}:{$c->port}",
            'via' => $c->via,
            'server' => $c->server?->name,
            'docker_container' => $c->docker_container,
            'options' => $options,
            'exclude_tables' => $c->exclude_tables ?? [],
            'schema_only_tables' => $c->schema_only_tables ?? [],
        ]);

        $failed = [];
        $report = [];
        foreach ($databases as $db) {
            $backup->appendLog("Дампим {$db}");
            [$command, $ext] = $c->engine === DatabaseConnection::MYSQL ? self::mysqlCommand($c, $db, $options) : self::postgresCommand($c, $db, $options);

            $started = hrtime(true);
            $file = new BackupFile($backup, self::fileName($db).$ext);
            $result = $this->runner->stream($c, $command, $file->write(...), (int) config('backup.run_timeout'));
            $file->close();

            $error = null;
            if ($result['exit_code'] !== 0) {
                $error = mb_substr(DatabaseClient::explain($c, $result['exit_code'], trim($result['stderr'])), -2000);
                $backup->appendLog("Ошибка дампа {$db}: {$error}");
                $failed[] = $db;
            }
            $report[] = [
                'name' => $db,
                // размер базы в СУБД (данные и индексы) — для сравнения с размером дампа
                'size' => $sizes[$db] ?? null,
                'file' => $file->name,
                'dump_size' => $file->size,
                // объём SQL до сжатия (для .gz)
                'uncompressed_size' => $file->uncompressedSize,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'status' => $error === null ? 'success' : 'failed',
                'error' => $error,
            ];
            $backup->putMeta('databases', $report);
        }

        if ($failed !== []) {
            throw new BackupException('Не удалось сделать дамп: '.implode(', ', $failed).'.');
        }
        $backup->appendLog('Дампы получены');
    }

    private function version(DatabaseConnection $c): ?string
    {
        try {
            return $this->client->version($c) ?: $c->server_version;
        } catch (DatabaseException|SshConnectionException) {
            return $c->server_version;
        }
    }

    /** Имя файла из имени базы — без символов, опасных для файловой системы. */
    private static function fileName(string $db): string
    {
        return preg_replace('/[^A-Za-z0-9_.\-]/', '_', $db) ?: 'db';
    }

    /**
     * Таблицы базы из списка «база.таблица» (для PostgreSQL — «база.схема.таблица»).
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    private static function tablesOf(array $list, string $db): array
    {
        $prefix = $db.'.';

        return array_values(array_map(fn ($t) => substr($t, strlen($prefix)), array_filter($list, fn ($t) => str_starts_with($t, $prefix))));
    }

    /**
     * Данные без исключённых и «только структура» таблиц, затем структура последних.
     *
     * @param  array<string, mixed>  $o
     * @return array{string, string}
     */
    public static function mysqlCommand(DatabaseConnection $c, string $db, array $o): array
    {
        $q = 'escapeshellarg';
        $dump = '"$(command -v mysqldump || command -v mariadb-dump || echo mysqldump)" --quick --hex-blob --default-character-set=utf8mb4'
            .' -h '.$q($c->host).' -P '.(int) $c->port.' -u '.$q($c->username);

        $schemaOnly = self::tablesOf($c->schema_only_tables ?? [], $db);
        $ignore = [...self::tablesOf($c->exclude_tables ?? [], $db), ...$schemaOnly];

        $data = $dump
            .match ($o['lock_mode']) {
                'single_transaction' => ' --single-transaction',
                'lock_tables' => ' --lock-tables',
                default => ' --skip-lock-tables',
            }
        .($o['routines'] ? ' --routines' : '')
        .($o['events'] ? ' --events' : '')
        .($o['triggers'] ? '' : ' --skip-triggers')
        .implode('', array_map(fn ($t) => ' --ignore-table='.$q($db.'.'.$t), $ignore))
        .' --databases '.$q($db);

        $client = $schemaOnly === []
            ? $data
            : $data.' && '.$dump.' --no-data --skip-lock-tables '.$q($db).' '.implode(' ', array_map($q, $schemaOnly));

        return self::compressed(DatabaseClient::withPassword($c, $client), $o['compress'], '.sql');
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array{string, string}
     */
    public static function postgresCommand(DatabaseConnection $c, string $db, array $o): array
    {
        $q = 'escapeshellarg';
        $custom = $o['format'] === 'custom';
        $client = 'pg_dump -h '.$q($c->host).' -p '.(int) $c->port.' -U '.$q($c->username).' -d '.$q($db)
            .($custom ? ' -Fc'.($o['compress'] ? '' : ' -Z0') : ' -Fp')
            .($o['no_owner'] ? ' -O' : '')
            .implode('', array_map(fn ($t) => ' -T '.$q($t), self::tablesOf($c->exclude_tables ?? [], $db)))
            .implode('', array_map(fn ($t) => ' --exclude-table-data='.$q($t), self::tablesOf($c->schema_only_tables ?? [], $db)));

        $command = DatabaseClient::withPassword($c, $client);

        // Формат custom сжат самим pg_dump
        return $custom ? [$command, '.dump'] : self::compressed($command, $o['compress'], '.sql');
    }

    /**
     * Сжатие на стороне сервера — по сети идёт меньше. pipefail, чтобы ошибка дампа не терялась за gzip.
     *
     * @return array{string, string}
     */
    private static function compressed(string $command, bool $compress, string $ext): array
    {
        return $compress ? ['(set -o pipefail) 2>/dev/null && set -o pipefail; ( '.$command.' ) | gzip -c', $ext.'.gz'] : [$command, $ext];
    }
}
