<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Throwable;

/**
 * Бекап каталогов сервера: команда перед бекапом, затем tar.gz выбранных каталогов потоком по SSH.
 */
class ServerBackupRunner
{
    public function __construct(private SshConnectorInterface $ssh) {}

    public function run(Server $server, Backup $backup): void
    {
        $settings = $server->backupSettingsOrDefault();
        if ($settings->paths === []) {
            throw new BackupException('Не выбраны каталоги для бекапа.');
        }

        $backup->putMeta('source', [
            'type' => 'server',
            'name' => $server->name,
            'address' => "{$server->username}@{$server->host}:{$server->port}",
            'paths' => $settings->paths,
            'excludes' => $settings->excludes ?? [],
        ]);
        $this->collectHost($server, $backup);

        if (filled($settings->pre_command)) {
            $backup->appendLog('Выполняем команду перед бекапом');
            $started = hrtime(true);
            $result = $this->ssh->exec($server, $settings->pre_command, $settings->pre_command_timeout);
            $backup->putMeta('pre_command', [
                'command' => $settings->pre_command,
                'exit_code' => $result['exit_code'],
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                // хвост вывода — для разбора ошибок, целиком не храним
                'output' => mb_substr(trim($result['stdout']."\n".$result['stderr']), -4000),
                'continued_on_error' => $result['exit_code'] !== 0 && $settings->pre_command_continue_on_error,
            ]);
            if ($result['exit_code'] !== 0) {
                $message = 'Команда перед бекапом завершилась с кодом '.($result['exit_code'] ?? '—')
                    .(trim($result['stderr']) !== '' ? ': '.mb_substr(trim($result['stderr']), -2000) : '');
                if (! $settings->pre_command_continue_on_error) {
                    throw new BackupException($message);
                }
                $backup->appendLog($message.'. Продолжаем бекап.');
            }
        }

        $backup->appendLog('Архивируем: '.implode(', ', $settings->paths));
        $started = hrtime(true);
        $file = new BackupFile($backup, 'files.tar.gz');
        $result = $this->ssh->stream($server, self::tarCommand($settings->paths, $settings->excludes ?? []), $file->write(...), (int) config('backup.run_timeout'));
        $file->close();

        // GNU tar с --totals пишет в stderr «Total bytes written: N» — объём до сжатия; иначе (BusyBox) — посчитанный при приёме
        $stderr = trim($result['stderr']);
        $uncompressed = preg_match('/^Total bytes written: (\d+)/m', $stderr, $m) ? (int) $m[1] : $file->uncompressedSize;
        $stderr = mb_substr(trim((string) preg_replace('/^Total bytes written:.*$\R?/m', '', $stderr)), -2000);
        $backup->putMeta('archive', [
            'format' => 'tar.gz',
            'uncompressed_size' => $uncompressed,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'warnings' => $result['exit_code'] === 1 && $stderr !== '' ? $stderr : null,
        ]);

        // У GNU tar 1 — часть файлов изменилась во время чтения: архив пригоден, но стоит знать
        if ($result['exit_code'] === 1 && $file->size > 0 && ! str_contains($stderr, 'BusyBox')) {
            $backup->appendLog('tar: часть файлов менялась во время архивации'.($stderr !== '' ? ": {$stderr}" : ''));
        } elseif ($result['exit_code'] !== 0) {
            throw new BackupException('tar завершился с кодом '.($result['exit_code'] ?? '—').($stderr !== '' ? ": {$stderr}" : ''));
        }
        $backup->appendLog('Архив получен');
    }

    /** Сведения о сервере для истории: имя, ОС, ядро, версия tar. Не удалось — бекап это не останавливает. */
    private function collectHost(Server $server, Backup $backup): void
    {
        try {
            $r = $this->ssh->exec($server, self::HOST_INFO_COMMAND, 20);
            $backup->putMeta('host', self::parseHostInfo($r['stdout']));
        } catch (Throwable) {
            // нет сведений — не страшно
        }
    }

    /** Выводит пары «ключ=значение»; os — из /etc/os-release (или redhat-release для старых систем). */
    public const HOST_INFO_COMMAND = 'echo "hostname=$(hostname 2>/dev/null)"; echo "kernel=$(uname -sr 2>/dev/null)"; '
        .'echo "os=$( (. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME") || cat /etc/redhat-release 2>/dev/null)"; '
        .'echo "tar=$(tar --version 2>&1 | head -n 1)"';

    /**
     * @return array{hostname: string|null, os: string|null, kernel: string|null, tools: array<string, string>}
     */
    public static function parseHostInfo(string $stdout): array
    {
        $kv = [];
        foreach (preg_split('/\R/', $stdout) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $kv[trim($k)] = trim($v) ?: null;
            }
        }

        return [
            'hostname' => $kv['hostname'] ?? null,
            'os' => $kv['os'] ?? null,
            'kernel' => $kv['kernel'] ?? null,
            'tools' => array_filter(['tar' => $kv['tar'] ?? null]),
        ];
    }

    /**
     * Пути архивируются относительно / (без ведущего слэша) — архив безопасно распаковывать куда угодно.
     * Нечитаемые файлы GNU tar пропускает; в BusyBox (Alpine) такого флага нет.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $excludes
     */
    public static function tarCommand(array $paths, array $excludes): string
    {
        $rel = fn (string $p) => ltrim($p, '/') ?: '.';

        $args = ' -C /'
            .implode('', array_map(fn ($x) => ' --exclude='.escapeshellarg($rel($x)), $excludes))
            .' --'.implode('', array_map(fn ($p) => ' '.escapeshellarg($rel($p)), $paths));

        return 'if tar --version 2>/dev/null | grep -q GNU; then exec tar -czf - --ignore-failed-read --totals'.$args.'; else exec tar -czf -'.$args.'; fi';
    }
}
