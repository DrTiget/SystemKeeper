<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;

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

        if (filled($settings->pre_command)) {
            $backup->appendLog('Выполняем команду перед бекапом');
            $result = $this->ssh->exec($server, $settings->pre_command, $settings->pre_command_timeout);
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
        $file = new BackupFile($backup, 'files.tar.gz');
        $result = $this->ssh->stream($server, self::tarCommand($settings->paths, $settings->excludes ?? []), $file->write(...), (int) config('backup.run_timeout'));
        $file->close();

        // У GNU tar 1 — часть файлов изменилась во время чтения: архив пригоден, но стоит знать
        $stderr = mb_substr(trim($result['stderr']), -2000);
        if ($result['exit_code'] === 1 && $file->size > 0 && ! str_contains($stderr, 'BusyBox')) {
            $backup->appendLog('tar: часть файлов менялась во время архивации'.($stderr !== '' ? ": {$stderr}" : ''));
        } elseif ($result['exit_code'] !== 0) {
            throw new BackupException('tar завершился с кодом '.($result['exit_code'] ?? '—').($stderr !== '' ? ": {$stderr}" : ''));
        }
        $backup->appendLog('Архив получен');
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

        return 'if tar --version 2>/dev/null | grep -q GNU; then exec tar -czf - --ignore-failed-read'.$args.'; else exec tar -czf -'.$args.'; fi';
    }
}
