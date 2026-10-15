<?php

namespace App\Services\Database;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\DatabaseConnection;

/**
 * Запросы к СУБД через консольные клиенты (mysql / psql / clickhouse-client): версия, список баз и таблиц.
 * Клиент запускается на сервере по SSH (опционально внутри Docker-контейнера) или локально.
 */
class DatabaseClient
{
    public function __construct(private DatabaseCommandRunner $runner) {}

    /**
     * Пароль читается из stdin в переменную окружения клиента — не попадает в аргументы процессов.
     */
    public static function withPassword(DatabaseConnection $c, string $client): string
    {
        $env = match ($c->engine) {
            DatabaseConnection::MYSQL => 'MYSQL_PWD',
            DatabaseConnection::CLICKHOUSE => 'CLICKHOUSE_PASSWORD',
            default => 'PGPASSWORD',
        };

        return 'IFS= read -r SK_DB_PASSWORD; '.$env.'="$SK_DB_PASSWORD"; export '.$env.'; unset SK_DB_PASSWORD; '
            .self::inContainer($c, $client, '-e '.$env);
    }

    /**
     * Команда клиента — на сервере как есть, а при docker_container — внутри контейнера через `docker exec`
     * (`-e NAME` без значения передаёт переменную из окружения вызывающего shell).
     */
    public static function inContainer(DatabaseConnection $c, string $client, string $dockerFlags = ''): string
    {
        if ($c->via === DatabaseConnection::VIA_SSH && $c->docker_container) {
            return 'docker exec -i '.($dockerFlags !== '' ? $dockerFlags.' ' : '').escapeshellarg($c->docker_container)
                .' sh -c '.escapeshellarg('LC_ALL=C '.$client);
        }

        return 'LC_ALL=C '.$client;
    }

    /** Клиент MySQL: в новых образах MariaDB вместо mysql есть только mariadb. */
    public const MYSQL_BIN = '"$(command -v mysql || command -v mariadb || echo mysql)"';

    /** Клиент ClickHouse: отдельный clickhouse-client или общий бинарник `clickhouse client`. */
    public const CLICKHOUSE_BIN = '$(command -v clickhouse-client || echo clickhouse client)';

    /** clickhouse-client с параметрами подключения (пароль — из CLICKHOUSE_PASSWORD, см. withPassword). */
    public static function clickhouseClient(DatabaseConnection $c): string
    {
        $q = 'escapeshellarg';

        return self::CLICKHOUSE_BIN.' --host '.$q($c->host).' --port '.(int) $c->port.' --user '.$q($c->username).' --connect_timeout 10';
    }

    /** Имя в обратных кавычках для SQL ClickHouse. */
    public static function clickhouseName(string $name): string
    {
        return '`'.str_replace(['\\', '`'], ['\\\\', '\\`'], $name).'`';
    }

    /** Строковый литерал ClickHouse. */
    public static function clickhouseString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /** Командная строка клиента с параметрами подключения и SQL. */
    public static function queryCommand(DatabaseConnection $c, string $sql, ?string $database = null): string
    {
        $q = 'escapeshellarg';
        if ($c->engine === DatabaseConnection::CLICKHOUSE) {
            $client = self::clickhouseClient($c)
                .($database !== null ? ' --database '.$q($database) : '')
                .' --format TabSeparated --query '.$q($sql);
        } elseif ($c->engine === DatabaseConnection::MYSQL) {
            $client = self::MYSQL_BIN.' --batch --skip-column-names --connect-timeout=10'
                .' -h '.$q($c->host).' -P '.(int) $c->port.' -u '.$q($c->username)
                .($database !== null ? ' -D '.$q($database) : '')
                .' -e '.$q($sql);
        } else {
            $client = 'psql -X -q -A -t -v ON_ERROR_STOP=1 -F '.$q("\t")
                .' -h '.$q($c->host).' -p '.(int) $c->port.' -U '.$q($c->username)
                .' -d '.$q($database ?? ($c->maintenance_database ?: 'postgres'))
                .' -c '.$q($sql);
        }

        return self::withPassword($c, $client);
    }

    /**
     * Строки результата, разбитые по табуляции.
     *
     * @return list<list<string>>
     *
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    public function query(DatabaseConnection $c, string $sql, ?string $database = null): array
    {
        $result = $this->runner->run($c, self::queryCommand($c, $sql, $database), (int) config('backup.db_query_timeout', 30));

        if ($result['exit_code'] !== 0) {
            throw new DatabaseException(self::explain($c, $result['exit_code'], trim($result['stderr'])));
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', rtrim($result['stdout'], "\r\n")) as $line) {
            if ($line !== '') {
                $cells = explode("\t", $line);
                // ClickHouse экранирует переводы строк и табуляции внутри значений (TabSeparated)
                $rows[] = $c->engine === DatabaseConnection::CLICKHOUSE ? array_map(self::unescapeTsv(...), $cells) : $cells;
            }
        }

        return $rows;
    }

    public static function unescapeTsv(string $value): string
    {
        return preg_replace_callback('/\\\\(.)/s', fn ($m) => match ($m[1]) {
            'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", 'b' => "\x08", 'f' => "\f",
            default => $m[1],
        }, $value);
    }

    /** Понятный текст типичных ошибок клиента. */
    public static function explain(DatabaseConnection $c, ?int $code, string $stderr): string
    {
        $client = match ($c->engine) {
            DatabaseConnection::MYSQL => 'mysql',
            DatabaseConnection::CLICKHOUSE => 'clickhouse-client',
            default => 'psql',
        };
        // Предупреждения клиентов (пароль в окружении, устаревшее имя программы и т. п.) — не ошибка
        $stderr = trim((string) preg_replace('/^(?:(?:mysql|mariadb|psql): (?:\[Warning\]|Deprecated program name).*|WARNING: .*)\R?/mi', '', $stderr));

        return match (true) {
            $code === 127 || str_contains($stderr, 'command not found') || str_contains($stderr, 'not found') && str_contains($stderr, $client) => "Клиент {$client} не найден".($c->via === DatabaseConnection::VIA_SSH ? ($c->docker_container ? " в контейнере {$c->docker_container}" : ' на сервере') : '').'.',
            str_contains($stderr, 'caching_sha2_password could not be loaded') => 'Клиент MariaDB не поддерживает авторизацию MySQL 8 (caching_sha2_password): установите клиент MySQL'.($c->via === DatabaseConnection::VIA_SSH ? ' на сервер или подключайтесь через Docker-контейнер СУБД.' : '.'),
            str_contains($stderr, 'No such container') => "Контейнер {$c->docker_container} не найден.",
            str_contains($stderr, 'Access denied') || str_contains($stderr, 'password authentication failed') || str_contains($stderr, 'AUTHENTICATION_FAILED') || str_contains($stderr, 'Authentication failed') => 'Неверный пользователь или пароль БД.',
            str_contains($stderr, "Can't connect") || str_contains($stderr, 'Connection refused') || str_contains($stderr, 'could not connect') || str_contains($stderr, 'Unknown MySQL server host') || str_contains($stderr, 'could not translate host name') || str_contains($stderr, 'NETWORK_ERROR') || str_contains($stderr, 'DNS_ERROR') => "Не удалось подключиться к {$c->host}:{$c->port}: ".$stderr,
            default => $stderr !== '' ? $stderr : 'Клиент БД завершился с кодом '.($code ?? '—').'.',
        };
    }

    /**
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    public function version(DatabaseConnection $c): string
    {
        return $this->query($c, $c->engine === DatabaseConnection::CLICKHOUSE ? 'SELECT version()' : 'SELECT VERSION()')[0][0] ?? '';
    }

    /**
     * Базы данных с размером, служебные отмечены.
     *
     * @return list<array{name: string, size: int|null, system: bool}>
     *
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    public function databases(DatabaseConnection $c): array
    {
        $sql = $c->engine === DatabaseConnection::CLICKHOUSE
            ? 'SELECT d.name, sum(p.bytes_on_disk) FROM system.databases d '
                .'LEFT JOIN (SELECT database, bytes_on_disk FROM system.parts WHERE active) p ON p.database = d.name GROUP BY d.name ORDER BY d.name'
            : ($c->engine === DatabaseConnection::MYSQL
            ? 'SELECT s.schema_name, COALESCE(SUM(t.data_length + t.index_length), 0) FROM information_schema.schemata s '
                .'LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name GROUP BY s.schema_name ORDER BY s.schema_name'
            : 'SELECT datname, pg_database_size(datname) FROM pg_database WHERE datallowconn AND NOT datistemplate ORDER BY datname');

        $system = DatabaseConnection::SYSTEM_DATABASES[$c->engine];

        return array_map(fn ($r) => [
            'name' => $r[0],
            'size' => isset($r[1]) && is_numeric($r[1]) ? (int) $r[1] : null,
            'system' => in_array($r[0], $system, true),
        ], $this->query($c, $sql));
    }

    /**
     * Таблицы базы: имя (для PostgreSQL — schema.table), примерное число строк, размер.
     *
     * @return list<array{name: string, rows: int|null, size: int|null}>
     *
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    public function tables(DatabaseConnection $c, string $database): array
    {
        if ($c->engine === DatabaseConnection::CLICKHOUSE) {
            $rows = $this->query($c, 'SELECT name, total_rows, total_bytes FROM system.tables WHERE database = '.self::clickhouseString($database)
                ." AND NOT is_temporary AND name NOT LIKE '.inner%' ORDER BY name");
        } elseif ($c->engine === DatabaseConnection::MYSQL) {
            $db = str_replace(['\\', "'"], ['\\\\', "\\'"], $database);
            $rows = $this->query($c, 'SELECT table_name, table_rows, data_length + index_length FROM information_schema.tables '
                ."WHERE table_schema = '{$db}' AND table_type = 'BASE TABLE' ORDER BY table_name");
        } else {
            $rows = $this->query($c, "SELECT schemaname || '.' || relname, n_live_tup, pg_total_relation_size(relid) FROM pg_stat_user_tables ORDER BY 1", $database);
        }

        return array_map(fn ($r) => [
            'name' => $r[0],
            'rows' => isset($r[1]) && is_numeric($r[1]) ? (int) $r[1] : null,
            'size' => isset($r[2]) && is_numeric($r[2]) ? (int) $r[2] : null,
        ], $rows);
    }
}
