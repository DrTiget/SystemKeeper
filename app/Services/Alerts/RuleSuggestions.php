<?php

namespace App\Services\Alerts;

use App\Models\AlertRule;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Server;

/**
 * «Рекомендуемые правила»: базовый набор алертов на все проекты. Предлагается только то, для чего есть объекты,
 * и чего ещё не покрывает включённое правило того же вида на все проекты и все объекты (у видов с порогом — с той же важностью).
 * Значения совпадают с ALERT_KINDS.defaults из frontend/src/api/alerts.ts, кроме добавочного «диск 95% — критично».
 */
class RuleSuggestions
{
    /**
     * Ключ → поля правила. Порядок — как в интерфейсе.
     *
     * @var array<string, array{name: string, kind: string, severity: string, threshold: int|null, duration: int}>
     */
    private const CATALOG = [
        'server_unreachable' => ['name' => 'Сервер не отвечает', 'kind' => AlertKinds::SERVER_UNREACHABLE, 'severity' => AlertRule::CRITICAL, 'threshold' => null, 'duration' => 3],
        'server_disk_warning' => ['name' => 'Диск заполнен больше 85%', 'kind' => AlertKinds::SERVER_DISK, 'severity' => AlertRule::WARNING, 'threshold' => 85, 'duration' => 0],
        'server_disk_critical' => ['name' => 'Диск заполнен больше 95%', 'kind' => AlertKinds::SERVER_DISK, 'severity' => AlertRule::CRITICAL, 'threshold' => 95, 'duration' => 0],
        'server_memory' => ['name' => 'Память занята больше 90%', 'kind' => AlertKinds::SERVER_MEMORY, 'severity' => AlertRule::WARNING, 'threshold' => 90, 'duration' => 5],
        'server_cpu' => ['name' => 'CPU выше 90%', 'kind' => AlertKinds::SERVER_CPU, 'severity' => AlertRule::WARNING, 'threshold' => 90, 'duration' => 5],
        'server_load' => ['name' => 'Нагрузка на ядро больше 2', 'kind' => AlertKinds::SERVER_LOAD, 'severity' => AlertRule::WARNING, 'threshold' => 2, 'duration' => 10],
        'monitor_down' => ['name' => 'Монитор недоступен', 'kind' => AlertKinds::MONITOR_DOWN, 'severity' => AlertRule::CRITICAL, 'threshold' => null, 'duration' => 0],
        'monitor_ssl' => ['name' => 'Сертификат истекает через 14 дн.', 'kind' => AlertKinds::MONITOR_SSL, 'severity' => AlertRule::WARNING, 'threshold' => 14, 'duration' => 0],
        'backup_failed' => ['name' => 'Бекап не удался', 'kind' => AlertKinds::BACKUP_FAILED, 'severity' => AlertRule::CRITICAL, 'threshold' => null, 'duration' => 0],
        'backup_missing' => ['name' => 'Нет успешного бекапа дольше 26 ч', 'kind' => AlertKinds::BACKUP_MISSING, 'severity' => AlertRule::WARNING, 'threshold' => 26, 'duration' => 0],
        'storage_full' => ['name' => 'Хранилище заполнено больше 90%', 'kind' => AlertKinds::STORAGE_FULL, 'severity' => AlertRule::WARNING, 'threshold' => 90, 'duration' => 0],
    ];

    /** Напоминать о критичных каждый час; предупреждения — один раз */
    private const CRITICAL_REPEAT_MINUTES = 60;

    /**
     * Что имеет смысл предложить прямо сейчас.
     *
     * @return list<array{key: string, name: string, kind: string, severity: string, threshold: int|null, duration: int, project_id: null, targets: array{}, repeat_every: int|null, notify_resolved: bool, reason: string, objects_count: int}>
     */
    public function all(): array
    {
        $covered = AlertRule::query()->where('enabled', true)->whereNull('project_id')->get(['kind', 'severity', 'targets'])
            ->filter(fn (AlertRule $r) => ($r->targets ?? []) === [])
            ->map(fn (AlertRule $r) => $this->coverageKey($r->kind, $r->severity))->all();
        $objects = $this->objects();

        $result = [];
        foreach (self::CATALOG as $key => $def) {
            [$count, $reason] = $objects[$def['kind']];
            if ($count === 0 || in_array($this->coverageKey($def['kind'], $def['severity']), $covered, true)) {
                continue;
            }
            $result[] = ['key' => $key] + $this->fields($key) + ['objects_count' => $count, 'reason' => $reason];
        }

        return $result;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Поля правила в виде тела POST /alert-rules (без каналов).
     *
     * @return array{name: string, kind: string, severity: string, threshold: int|null, duration: int, project_id: null, targets: array{}, repeat_every: int|null, notify_resolved: bool}
     */
    public function fields(string $key): array
    {
        $def = self::CATALOG[$key];

        return $def + [
            'project_id' => null,
            'targets' => [],
            'repeat_every' => $def['severity'] === AlertRule::CRITICAL ? self::CRITICAL_REPEAT_MINUTES : null,
            'notify_resolved' => true,
        ];
    }

    /** У видов с порогом покрытие зависит от важности (85% warning не заменяет 95% critical), у остальных — нет. */
    private function coverageKey(string $kind, string $severity): string
    {
        return AlertKinds::threshold($kind) === null ? $kind : "{$kind}:{$severity}";
    }

    /**
     * Вид → [сколько объектов попадёт под правило, пояснение]; сколько объектов считается — как их видит ConditionEvaluator.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private function objects(): array
    {
        // сервер без строки настроек сбора — со значениями по умолчанию, то есть сбор включён
        $servers = Server::query()->where(fn ($q) => $q->whereDoesntHave('metrics')->orWhereHas('metrics', fn ($m) => $m->where('enabled', true)))->count();
        $serversReason = $this->count($servers, ['сервер', 'сервера', 'серверов']).' с метриками';

        $monitors = Monitor::query()->where('enabled', true);
        $https = (clone $monitors)->where('type', Monitor::HTTP)->where('url', 'like', 'https://%')->count();

        $backupServers = Server::query()->whereHas('backupSettings', fn ($q) => $q->where('enabled', true))->count();
        $backupDatabases = DatabaseConnection::query()->where('backup_enabled', true)->count();
        $backupReason = collect([
            $backupServers ? $this->count($backupServers, ['сервер', 'сервера', 'серверов']) : null,
            $backupDatabases ? $this->count($backupDatabases, ['база', 'базы', 'баз']) : null,
        ])->filter()->join(' и ').' с бекапом по расписанию';

        // у S3 размера нет — такие хранилища правило пропускает
        $storages = BackupStorage::query()->whereIn('type', [BackupStorage::LOCAL, BackupStorage::YANDEX_DISK])->count();

        $monitorsCount = $monitors->count();
        $serversEntry = [$servers, $serversReason];
        $backupsEntry = [$backupServers + $backupDatabases, $backupReason];

        return [
            AlertKinds::SERVER_UNREACHABLE => $serversEntry,
            AlertKinds::SERVER_DISK => $serversEntry,
            AlertKinds::SERVER_MEMORY => $serversEntry,
            AlertKinds::SERVER_CPU => $serversEntry,
            AlertKinds::SERVER_LOAD => $serversEntry,
            AlertKinds::MONITOR_DOWN => [$monitorsCount, $this->count($monitorsCount, ['монитор', 'монитора', 'мониторов'])],
            AlertKinds::MONITOR_SSL => [$https, $this->count($https, ['HTTPS-монитор', 'HTTPS-монитора', 'HTTPS-мониторов'])],
            AlertKinds::BACKUP_FAILED => $backupsEntry,
            AlertKinds::BACKUP_MISSING => $backupsEntry,
            AlertKinds::STORAGE_FULL => [$storages, $this->count($storages, ['хранилище', 'хранилища', 'хранилищ']).' с известным размером'],
        ];
    }

    /** «1 сервер», «3 сервера», «5 серверов» */
    private function count(int $n, array $forms): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        $form = ($mod100 >= 11 && $mod100 <= 14) || $mod10 === 0 || $mod10 >= 5 ? $forms[2] : ($mod10 === 1 ? $forms[0] : $forms[1]);

        return "{$n} {$form}";
    }
}
