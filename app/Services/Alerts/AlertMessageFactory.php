<?php

namespace App\Services\Alerts;

use App\Events\MetricThresholdExceeded;
use App\Events\MetricThresholdRecovered;
use App\Models\Backup;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StorageCopy;
use Illuminate\Support\Str;

/** Шаблоны сообщений на русском со ссылкой на объект во фронте. */
class AlertMessageFactory
{
    public function incidentOpened(Incident $incident): AlertMessage
    {
        $monitor = $incident->monitor;

        return new AlertMessage(
            'incident.opened',
            "🔴 Монитор «{$monitor->name}» недоступен",
            'Причина: '.($incident->reason ?: 'не указана')."\nНачало: {$incident->started_at->format('d.m.Y H:i:s')}",
            $this->monitorUrl($monitor),
            "monitor:{$monitor->id}",
            monitorId: $monitor->id,
        );
    }

    public function incidentResolved(Incident $incident): AlertMessage
    {
        $monitor = $incident->monitor;

        return new AlertMessage(
            'incident.resolved',
            "🟢 Монитор «{$monitor->name}» снова доступен",
            'Простой длился '.$this->duration($incident->durationSeconds()).'.',
            $this->monitorUrl($monitor),
            "monitor:{$monitor->id}",
            monitorId: $monitor->id,
        );
    }

    public function backupFailed(Backup $backup): AlertMessage
    {
        $job = $backup->job;

        return new AlertMessage(
            'backup.failed',
            "🔴 Бекап «{$job->name}» не удался",
            'Ошибка: '.($backup->error ?: 'не указана'),
            $this->url('/backups'),
            "backup_job:{$job->id}",
            backupJobId: $job->id,
            serverId: $job->server_id,
        );
    }

    public function backupSucceeded(Backup $backup): AlertMessage
    {
        $job = $backup->job;
        $size = $backup->size_bytes !== null ? ' Размер: '.$this->bytes($backup->size_bytes).'.' : '';

        return new AlertMessage(
            'backup.succeeded',
            "🟢 Бекап «{$job->name}» выполнен",
            'Бекап успешно завершён.'.$size,
            $this->url('/backups'),
            "backup_job:{$job->id}",
            backupJobId: $job->id,
            serverId: $job->server_id,
        );
    }

    public function backupRestoreFailed(Backup $backup): AlertMessage
    {
        $job = $backup->job;

        return new AlertMessage(
            'backup.restore_failed',
            "🔴 Тестовое восстановление бекапа «{$job->name}» не удалось",
            'Причина: '.(Str::limit(trim((string) $backup->restore_log), 300) ?: 'не указана'),
            $this->url('/backups'),
            "backup_job:{$job->id}",
            backupJobId: $job->id,
            serverId: $job->server_id,
        );
    }

    public function metricExceeded(MetricThresholdExceeded $e): AlertMessage
    {
        [$label, $unit] = $this->metricLabel($e->metric, $e->target);

        return new AlertMessage(
            'metric.threshold_exceeded',
            "🟠 {$label} на сервере «{$e->server->name}» выше порога",
            "Значение: {$this->num($e->value)}{$unit}, порог: {$this->num($e->threshold)}{$unit}.",
            $this->url("/servers/{$e->server->id}"),
            "server:{$e->server->id}:{$e->metric}:{$e->target}",
            serverId: $e->server->id,
        );
    }

    public function metricRecovered(MetricThresholdRecovered $e): AlertMessage
    {
        [$label, $unit] = $this->metricLabel($e->metric, $e->target);
        $since = $e->exceededSince ? ' Превышение было с '.$e->exceededSince->format('d.m.Y H:i').'.' : '';

        return new AlertMessage(
            'metric.threshold_recovered',
            "🟢 {$label} на сервере «{$e->server->name}» в норме",
            "Значение: {$this->num($e->value)}{$unit}, порог: {$this->num($e->threshold)}{$unit}.".$since,
            $this->url("/servers/{$e->server->id}"),
            "server:{$e->server->id}:{$e->metric}:{$e->target}",
            serverId: $e->server->id,
        );
    }

    /** @return array{0: string, 1: string} название метрики и единица */
    private function metricLabel(string $metric, ?string $target): array
    {
        return match ($metric) {
            'disk' => ['Диск'.($target ? " {$target}" : ''), '%'],
            'ram' => ['Память', '%'],
            'load' => ['Нагрузка (load average на ядро)', ''],
            default => [$metric, ''],
        };
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    public function storageCopyFailed(StorageCopy $copy): AlertMessage
    {
        $target = $copy->target;
        $name = $target?->name ?? "#{$copy->storage_target_id}";

        return new AlertMessage(
            'storage_copy.failed',
            "🔴 Не удалось загрузить копию в хранилище «{$name}»",
            "Файл: {$copy->remote_path}\nОшибка: ".($copy->error ?: 'не указана'),
            $this->url('/storage-targets'),
            "storage_target:{$copy->storage_target_id}",
            storageTargetId: $copy->storage_target_id,
        );
    }

    public function sslExpiring(Monitor $monitor, int $daysLeft): AlertMessage
    {
        return new AlertMessage(
            'ssl.expiring',
            "🟠 SSL-сертификат «{$monitor->name}» скоро истечёт",
            "Осталось дней: {$daysLeft} (порог: {$monitor->ssl_expiry_days}).",
            $this->monitorUrl($monitor),
            "monitor:{$monitor->id}",
            monitorId: $monitor->id,
        );
    }

    /** Тестовое сообщение канала. */
    public function test(string $channelName): AlertMessage
    {
        return new AlertMessage('test', '🔔 Тест SystemKeeper', "Канал «{$channelName}» настроен верно.", $this->url('/'));
    }

    private function monitorUrl(Monitor $monitor): string
    {
        return $this->url("/monitoring/{$monitor->id}");
    }

    private function url(string $path): string
    {
        return config('alerts.frontend_url').$path;
    }

    private function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? "{$h} ч {$m} мин" : ($m > 0 ? "{$m} мин {$s} с" : "{$s} с");
    }

    private function bytes(int $bytes): string
    {
        foreach (['Б', 'КБ', 'МБ', 'ГБ'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'ГБ') {
                return round($bytes / 1024 ** $i, 1).' '.$unit;
            }
        }
    }
}
