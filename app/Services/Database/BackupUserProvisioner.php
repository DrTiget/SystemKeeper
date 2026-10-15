<?php

namespace App\Services\Database;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\DatabaseConnection;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Support\Str;

/**
 * Создаёт в СУБД отдельного пользователя для бекапов с правами только на чтение (через SSH-сервер подключения)
 * и сохраняет его логин и сгенерированный пароль в подключении.
 *
 * Права администратора БД:
 *  - local — локальный доступ на сервере: MySQL — root по unix-сокету (от root или через sudo -n),
 *    PostgreSQL — пользователь postgres (su / sudo -n); в Docker — root-пароль из переменных контейнера
 *    (MYSQL_ROOT_PASSWORD / MARIADB_ROOT_PASSWORD) или POSTGRES_USER;
 *  - credentials — логин и пароль администратора, используются один раз и не сохраняются.
 * SQL и пароли передаются через stdin — в аргументы процессов не попадают.
 */
class BackupUserProvisioner
{
    public function __construct(private SshConnectorInterface $ssh, private DatabaseClient $client) {}

    /**
     * @param  array{mode: string, username?: string|null, password?: string|null}  $admin
     * @return list<string> что было сделано (для показа пользователю)
     *
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    public function provision(DatabaseConnection $c, string $username, array $admin): array
    {
        if ($c->engine === DatabaseConnection::CLICKHOUSE) {
            throw new DatabaseException('Автонастройка пользователя для ClickHouse пока не поддерживается — создайте пользователя с правом SELECT и SHOW вручную.');
        }
        $password = Str::password(32, symbols: false);
        $log = [];

        $sql = $c->engine === DatabaseConnection::MYSQL ? $this->mysqlSql($c, $username, $password) : $this->postgresSql($username, $password);
        $this->runAdmin($c, $admin, $sql);
        $log[] = "Пользователь {$username} создан (или обновлён) с новым паролем.";

        if ($c->engine === DatabaseConnection::MYSQL) {
            // SHOW_ROUTINE нужен mysqldump --routines в MySQL 8.0.20+; в MariaDB и старых версиях его нет — не ошибка
            try {
                $this->runAdmin($c, $admin, implode('', array_map(fn ($h) => "GRANT SHOW_ROUTINE ON *.* TO '{$username}'@'{$h}';", $this->mysqlHosts($c))));
                $log[] = 'Выдано право SHOW_ROUTINE (дамп процедур в MySQL 8).';
            } catch (DatabaseException) {
                // нет такого права в этой версии
            }
        }

        $c->update(['username' => $username, 'password' => $password]);

        // Проверяем, что новый пользователь действительно подключается
        $version = $this->client->version($c);
        $c->forceFill(['last_checked_at' => now(), 'last_check_status' => 'ok', 'last_check_error' => null, 'server_version' => $version])->save();
        $log[] = "Проверено подключение под {$username}: {$version}.";

        return $log;
    }

    /** Хосты MySQL-пользователя: локальное подключение — localhost/127.0.0.1/::1, иначе — любой. */
    private function mysqlHosts(DatabaseConnection $c): array
    {
        return in_array($c->host, ['127.0.0.1', 'localhost', '::1'], true) ? ['localhost', '127.0.0.1', '::1'] : ['%'];
    }

    private function mysqlSql(DatabaseConnection $c, string $u, string $p): string
    {
        $sql = '';
        foreach ($this->mysqlHosts($c) as $h) {
            $sql .= "CREATE USER IF NOT EXISTS '{$u}'@'{$h}' IDENTIFIED BY '{$p}';"
                ."ALTER USER '{$u}'@'{$h}' IDENTIFIED BY '{$p}';"
                ."GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, PROCESS ON *.* TO '{$u}'@'{$h}';";
        }

        return $sql;
    }

    private function postgresSql(string $u, string $p): string
    {
        // pg_read_all_data (PostgreSQL 14+) — чтение всех таблиц и последовательностей во всех базах
        return "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$u}') THEN CREATE ROLE \"{$u}\" LOGIN; END IF; END \$\$;\n"
            ."ALTER ROLE \"{$u}\" WITH LOGIN PASSWORD '{$p}';\n"
            ."GRANT pg_read_all_data TO \"{$u}\";\n";
    }

    /**
     * @param  array{mode: string, username?: string|null, password?: string|null}  $admin
     *
     * @throws DatabaseException
     * @throws SshConnectionException
     */
    private function runAdmin(DatabaseConnection $c, array $admin, string $sql): void
    {
        $mysql = $c->engine === DatabaseConnection::MYSQL;
        $docker = (bool) $c->docker_container;

        if ($admin['mode'] === 'credentials') {
            // Первая строка stdin — пароль администратора, остальное — SQL
            $q = 'escapeshellarg';
            $client = $mysql
                ? DatabaseClient::MYSQL_BIN.' --connect-timeout=10 -h '.$q($c->host).' -P '.(int) $c->port.' -u '.$q((string) $admin['username'])
                : 'psql -X -q -v ON_ERROR_STOP=1 -h '.$q($c->host).' -p '.(int) $c->port.' -U '.$q((string) $admin['username']).' -d '.$q($c->maintenance_database ?: 'postgres');
            $command = DatabaseClient::withPassword($c, $client);
            $stdin = ((string) ($admin['password'] ?? ''))."\n".$sql."\n";
        } else {
            if ($mysql) {
                $client = $docker
                    // root-пароль из переменных окружения контейнера (официальные образы MySQL/MariaDB)
                    ? 'p="${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}"; if [ -n "$p" ]; then export MYSQL_PWD="$p"; fi; exec '.DatabaseClient::MYSQL_BIN.' -uroot'
                    : 'M='.DatabaseClient::MYSQL_BIN.'; if [ "$(id -u)" -eq 0 ]; then exec "$M" -uroot; else exec sudo -n "$M" -uroot; fi';
            } else {
                $client = $docker
                    ? 'exec psql -X -q -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-postgres}" -d postgres'
                    : 'if [ "$(id -u)" -eq 0 ]; then exec su postgres -s /bin/sh -c "psql -X -q -v ON_ERROR_STOP=1 -d postgres"; else exec sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 -d postgres; fi';
            }
            $command = $docker ? DatabaseClient::inContainer($c, $client) : 'LC_ALL=C; '.$client;
            $stdin = $sql."\n";
        }

        $result = $this->ssh->exec($c->server, $command, (int) config('backup.db_query_timeout', 30), $stdin);

        if ($result['exit_code'] !== 0) {
            throw new DatabaseException(self::explain($c, $admin['mode'], trim($result['stderr'])));
        }
    }

    private static function explain(DatabaseConnection $c, string $mode, string $stderr): string
    {
        $stderr = trim((string) preg_replace('/^(?:(?:mysql|mariadb|psql): (?:\[Warning\]|Deprecated program name).*|WARNING: .*)\R?/mi', '', $stderr));

        return match (true) {
            str_contains($stderr, 'sudo: a password is required') || str_contains($stderr, 'sudo: a terminal is required') => 'Нет прав администратора: пользователь SSH не root, а sudo требует пароль. Подключите сервер под root или укажите логин администратора БД.',
            str_contains($stderr, 'pg_read_all_data') && str_contains($stderr, 'does not exist') => 'Автонастройка PostgreSQL требует версии 14 или новее (роль pg_read_all_data).',
            str_contains($stderr, 'Access denied') || str_contains($stderr, 'authentication failed') => $mode === 'credentials'
                    ? 'Неверный логин или пароль администратора БД.'
                    : 'Локальный вход администратора не сработал'.($c->docker_container ? ' (в контейнере нет root-пароля в переменных окружения)' : ' (у root MySQL задан пароль)').' — укажите логин и пароль администратора БД.',
            str_contains($stderr, 'not found') || str_contains($stderr, 'No such file') => 'Клиент СУБД не найден'.($c->docker_container ? " в контейнере {$c->docker_container}" : ' на сервере').': '.$stderr,
            default => $stderr !== '' ? $stderr : 'Не удалось создать пользователя.',
        };
    }
}
