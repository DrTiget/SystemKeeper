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
 * ClickHouse — схема базы, данные каждой таблицы в формате Native и отдельно представления (см. dumpClickhouse).
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
            if ($c->engine === DatabaseConnection::CLICKHOUSE) {
                $entry = $this->dumpClickhouse($c, $backup, $db, $options);
                $entry['size'] = $sizes[$db] ?? null;
                $report[] = $entry;
                if ($entry['error'] !== null) {
                    $failed[] = $db;
                }
                $backup->putMeta('databases', $report);

                continue;
            }
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

    /** Движки, у которых нет своих данных (или данные — в другой таблице): создаются после загрузки данных. */
    private const CLICKHOUSE_VIEWS = ['View', 'MaterializedView', 'LiveView', 'WindowView', 'Dictionary'];

    /**
     * ClickHouse: `<база>.schema.sql` (CREATE DATABASE и таблицы), `<база>.<таблица>.native.gz` — данные таблиц,
     * `<база>.views.sql` — представления и словари (их создают последними, иначе материализованные представления
     * посчитают загружаемые данные повторно). Данные выгружаются только у таблиц, которые их хранят (MergeTree, Log, Memory…).
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed> запись для meta.databases
     */
    private function dumpClickhouse(DatabaseConnection $c, Backup $backup, string $db, array $o): array
    {
        $started = hrtime(true);
        $name = self::fileName($db);
        $files = [];
        $errors = [];
        $dumpSize = 0;
        $uncompressed = 0;
        $str = DatabaseClient::clickhouseString(...);

        try {
            $createDb = $this->client->query($c, 'SHOW CREATE DATABASE '.DatabaseClient::clickhouseName($db))[0][0] ?? '';
            $tables = $this->client->query($c, 'SELECT name, engine, create_table_query FROM system.tables WHERE database = '.$str($db)
                ." AND NOT is_temporary AND name NOT LIKE '.inner%' ORDER BY name");
        } catch (DatabaseException|SshConnectionException $e) {
            $error = mb_substr($e->getMessage(), -2000);
            $backup->appendLog("Ошибка дампа {$db}: {$error}");

            return ['name' => $db, 'file' => null, 'files' => [], 'tables' => 0, 'dump_size' => 0, 'uncompressed_size' => null,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'status' => 'failed', 'error' => $error];
        }

        $excluded = self::tablesOf($c->exclude_tables ?? [], $db);
        $schemaOnly = self::tablesOf($c->schema_only_tables ?? [], $db);
        $tables = array_values(array_filter($tables, fn ($t) => ! in_array($t[0], $excluded, true)));
        $isView = fn (array $t) => in_array($t[1], self::CLICKHOUSE_VIEWS, true);

        $writeSql = function (string $file, array $statements) use ($backup, &$files, &$dumpSize) {
            $f = new BackupFile($backup, $file);
            $f->write($statements === [] ? '' : implode(";\n\n", $statements).";\n");
            $f->close();
            $files[] = $f->name;
            $dumpSize += $f->size;
        };

        $writeSql("{$name}.schema.sql", [
            str_replace('CREATE DATABASE', 'CREATE DATABASE IF NOT EXISTS', $createDb),
            ...array_map(fn ($t) => $t[2], array_filter($tables, fn ($t) => ! $isView($t))),
        ]);

        foreach ($tables as [$table, $engine]) {
            $storesData = str_contains($engine, 'MergeTree') || in_array($engine, ['Log', 'TinyLog', 'StripeLog', 'Memory', 'Set', 'Join'], true);
            if (! $storesData || in_array($table, $schemaOnly, true)) {
                continue;
            }
            $query = 'SELECT * FROM '.DatabaseClient::clickhouseName($db).'.'.DatabaseClient::clickhouseName($table).' FORMAT Native';
            [$command, $ext] = self::compressed(DatabaseClient::withPassword($c, DatabaseClient::clickhouseClient($c).' --query '.escapeshellarg($query)), $o['compress'], '.native');

            $file = new BackupFile($backup, $name.'.'.self::fileName($table).$ext);
            $result = $this->runner->stream($c, $command, $file->write(...), (int) config('backup.run_timeout'));
            $file->close();
            $files[] = $file->name;
            $dumpSize += $file->size;
            $uncompressed += $file->uncompressedSize ?? $file->size;

            if ($result['exit_code'] !== 0) {
                $errors[] = "{$table}: ".DatabaseClient::explain($c, $result['exit_code'], trim($result['stderr']));
            }
        }

        $views = array_filter($tables, $isView);
        if ($views !== []) {
            $writeSql("{$name}.views.sql", array_map(fn ($t) => $t[2], $views));
        }

        $error = $errors ? mb_substr(implode("\n", $errors), -2000) : null;
        if ($error !== null) {
            $backup->appendLog("Ошибка дампа {$db}: {$error}");
        }

        return [
            'name' => $db,
            'file' => "{$name}.schema.sql",
            'files' => $files,
            'tables' => count($tables),
            'dump_size' => $dumpSize,
            'uncompressed_size' => $uncompressed ?: null,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'status' => $error === null ? 'success' : 'failed',
            'error' => $error,
        ];
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
