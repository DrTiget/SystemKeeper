<?php

namespace App\Services\Alerts;

use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerMetrics;
use App\Services\Monitoring\MonitorStatus;
use App\Support\Bytes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Проверяет условие правила по всем объектам его области. Данные берутся из метрик серверов, мониторов,
 * бекапов и хранилищ — то, чего нет или что устарело, даёт `breached = null` («не знаем»): алерт не создаётся и не закрывается.
 */
class ConditionEvaluator
{
    /** @return list<Observation> */
    public function observe(AlertRule $rule): array
    {
        return match ($rule->kind) {
            AlertKinds::SERVER_CPU => $this->servers($rule, $this->cpu(...)),
            AlertKinds::SERVER_MEMORY => $this->servers($rule, $this->memory(...)),
            AlertKinds::SERVER_DISK => $this->servers($rule, $this->disk(...)),
            AlertKinds::SERVER_LOAD => $this->servers($rule, $this->load(...)),
            AlertKinds::SERVER_UNREACHABLE => $this->servers($rule, $this->unreachable(...)),
            AlertKinds::MONITOR_DOWN => $this->monitors($rule, $this->monitorDown(...)),
            AlertKinds::MONITOR_SSL => $this->monitors($rule, $this->monitorSsl(...)),
            AlertKinds::BACKUP_FAILED => $this->backups($rule, $this->backupFailed(...)),
            AlertKinds::BACKUP_MISSING => $this->backups($rule, $this->backupMissing(...)),
            AlertKinds::STORAGE_FULL => $this->storages($rule),
            default => [],
        };
    }

    // --- область правила

    /** id объектов типа из targets; null — targets пуст (все объекты), [] — для этого типа ничего не выбрано */
    private function targetIds(AlertRule $rule, string $type): ?array
    {
        $targets = $rule->targets ?? [];
        if ($targets === []) {
            return null;
        }

        return collect($targets)->where('type', $type)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param Builder<*> $query */
    private function scope(Builder $query, AlertRule $rule, string $type, bool $byProject = true): Builder
    {
        if ($byProject && $rule->project_id) {
            $query->where($query->getModel()->getTable().'.project_id', $rule->project_id);
        }
        $ids = $this->targetIds($rule, $type);

        return $ids === null ? $query : $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
    }

    // --- серверы

    /**
     * @param  callable(Server, ServerMetrics, AlertRule): array{0: bool|null, 1: string}  $check
     * @return list<Observation>
     */
    private function servers(AlertRule $rule, callable $check): array
    {
        $servers = $this->scope(Server::query()->with('metrics'), $rule, 'server')->orderBy('id')->get();

        return $servers->map(function (Server $server) use ($rule, $check) {
            [$breached, $message] = $server->metrics ? $check($server, $server->metrics, $rule) : [null, ''];

            return new Observation('server', $server->id, $server->name, $server->project_id, $breached, $message);
        })->all();
    }

    /** Показатели есть и свежие: иначе по ним судить нельзя. */
    private function fresh(ServerMetrics $metrics): bool
    {
        return $metrics->status() === ServerMetrics::OK && $metrics->snapshot !== null;
    }

    /** «… дольше N мин» к сообщению о превышении порога */
    private function lasting(AlertRule $rule): string
    {
        return $rule->duration > 0 ? " дольше {$rule->duration} мин" : '';
    }

    /** @return array{0: bool|null, 1: string} */
    private function cpu(Server $server, ServerMetrics $metrics, AlertRule $rule): array
    {
        $cpu = $this->fresh($metrics) ? ($metrics->snapshot['cpu'] ?? null) : null;

        return $cpu === null ? [null, ''] : [$cpu > $rule->threshold, 'CPU '.$this->number($cpu).'%'.$this->lasting($rule)];
    }

    /** @return array{0: bool|null, 1: string} */
    private function memory(Server $server, ServerMetrics $metrics, AlertRule $rule): array
    {
        $memory = $this->fresh($metrics) ? ($metrics->snapshot['memory'] ?? null) : null;
        if (! $memory || ! $memory['total']) {
            return [null, ''];
        }
        $percent = $memory['used'] / $memory['total'] * 100;

        return [$percent > $rule->threshold, 'Память занята на '.$this->number($percent).'%'.$this->lasting($rule)];
    }

    /** Любой диск выше порога; в сообщении — самый заполненный. */
    private function disk(Server $server, ServerMetrics $metrics, AlertRule $rule): array
    {
        if (! $this->fresh($metrics)) {
            return [null, ''];
        }
        $worst = null;
        foreach ($metrics->snapshot['disks'] ?? [] as $disk) {
            $percent = $disk['total'] > 0 ? $disk['used'] / $disk['total'] * 100 : 0;
            if ($worst === null || $percent > $worst[1]) {
                $worst = [$disk['mount'], $percent];
            }
        }
        if ($worst === null) {
            return [null, ''];
        }

        return [$worst[1] > $rule->threshold, "Диск «{$worst[0]}» заполнен на ".$this->number($worst[1]).'%'.$this->lasting($rule)];
    }

    /** Нагрузка за 5 минут на одно ядро. */
    private function load(Server $server, ServerMetrics $metrics, AlertRule $rule): array
    {
        $load5 = $this->fresh($metrics) ? ($metrics->snapshot['load'][1] ?? null) : null;
        $cores = max(1, (int) ($metrics->snapshot['cpu_cores'] ?? 1));
        if ($load5 === null) {
            return [null, ''];
        }
        $perCore = $load5 / $cores;

        return [$perCore > $rule->threshold, 'Нагрузка '.$this->number($perCore, 2).' на ядро (load5 '.$this->number($load5, 2).', ядер: '.$cores.')'.$this->lasting($rule)];
    }

    /** Устаревшие данные без ошибки — не «не отвечает», а «не знаем»; выключенный сбор алерт закрывает. */
    private function unreachable(Server $server, ServerMetrics $metrics, AlertRule $rule): array
    {
        $status = $metrics->status();
        if ($status === ServerMetrics::UNREACHABLE) {
            return [true, "Сервер {$server->name} не отвечает: ".Str::limit((string) $metrics->error, 300)];
        }

        return [$status === ServerMetrics::OK || $status === ServerMetrics::DISABLED ? false : null, ''];
    }

    // --- мониторы

    /**
     * @param  callable(Monitor, AlertRule): array{0: bool|null, 1: string}  $check
     * @return list<Observation>
     */
    private function monitors(AlertRule $rule, callable $check): array
    {
        return $this->scope(Monitor::query(), $rule, 'monitor')->orderBy('id')->get()->map(function (Monitor $monitor) use ($rule, $check) {
            [$breached, $message] = $check($monitor, $rule);

            return new Observation('monitor', $monitor->id, $monitor->name, $monitor->project_id, $breached, $message);
        })->all();
    }

    /** @return array{0: bool|null, 1: string} */
    private function monitorDown(Monitor $monitor): array
    {
        // выключенный и ещё не проверенный монитор не «упал»; повторные проверки (pending) не повод для тревоги
        if (! $monitor->enabled || $monitor->status !== Monitor::DOWN) {
            return [false, ''];
        }

        return [true, $this->monitorTitle($monitor).' недоступен'.($monitor->last_error ? ': '.Str::limit($monitor->last_error, 300) : '')];
    }

    /** @return array{0: bool|null, 1: string} */
    private function monitorSsl(Monitor $monitor, AlertRule $rule): array
    {
        if (! $monitor->enabled || ! $monitor->ssl_valid_to) {
            return [false, ''];
        }
        $days = MonitorStatus::daysLeft($monitor->ssl_valid_to);
        $title = 'Сертификат '.($monitor->type === Monitor::HTTP ? ($this->host((string) $monitor->url) ?: $monitor->name) : $monitor->name);

        return [$days < $rule->threshold, $days < 0 ? "{$title} истёк {$monitor->ssl_valid_to->format('d.m.Y')}" : "{$title} истекает через {$days} дн. ({$monitor->ssl_valid_to->format('d.m.Y')})"];
    }

    /** «Сайт example.com», «Порт db.local:5432», «Хост 10.0.0.1», «DNS example.com» */
    private function monitorTitle(Monitor $monitor): string
    {
        return match ($monitor->type) {
            Monitor::HTTP => 'Сайт '.($this->host((string) $monitor->url) ?: $monitor->name),
            Monitor::TCP => "Порт {$monitor->hostname}:{$monitor->port}",
            Monitor::PING => "Хост {$monitor->hostname}",
            default => "DNS {$monitor->hostname}",
        };
    }

    private function host(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }

    // --- бекапы

    /**
     * @param  callable(Server|DatabaseConnection, ?Backup, AlertRule): array{0: bool|null, 1: string}  $check
     * @return list<Observation>
     */
    private function backups(AlertRule $rule, callable $check): array
    {
        $sources = collect();
        foreach (['server' => Server::query()->with('backupSettings'), 'database' => DatabaseConnection::query()] as $type => $query) {
            $sources = $sources->concat($this->scope($query, $rule, $type)->orderBy('id')->get()->map(fn ($m) => [$type, $m]));
        }

        $observations = [];
        foreach ($sources->groupBy(0) as $type => $group) {
            $backups = $this->latestBackups($type, $group->pluck(1)->pluck('id')->all(), $rule->kind === AlertKinds::BACKUP_MISSING ? Backup::SUCCESS : null);
            foreach ($group as [, $source]) {
                [$breached, $message] = $check($source, $backups[$source->id] ?? null, $rule);
                $observations[] = new Observation($type, $source->id, $source->name, $source->project_id, $breached, $message);
            }
        }

        return $observations;
    }

    /**
     * Последний завершённый бекап каждого источника (с `$status` — последний с этим статусом).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Backup>
     */
    private function latestBackups(string $type, array $ids, ?string $status): Collection
    {
        $latest = Backup::query()->where('source_type', $type)->whereIn('source_id', $ids)
            ->whereIn('status', $status ? [$status] : [Backup::SUCCESS, Backup::FAILED])
            ->selectRaw('MAX(id) as id')->groupBy('source_id');

        return Backup::query()->whereIn('id', $latest)->get()->keyBy('source_id');
    }

    /** Последний завершённый бекап не удался. @return array{0: bool|null, 1: string} */
    private function backupFailed(Server|DatabaseConnection $source, ?Backup $latest): array
    {
        if (! $latest || $latest->status !== Backup::FAILED) {
            return [false, ''];
        }
        $kind = $source instanceof Server ? 'сервера' : 'базы';

        return [true, "Бекап {$kind} {$source->name} не удался".($latest->error ? ': '.Str::limit($latest->error, 300) : '')];
    }

    /**
     * Давно не было успешного бекапа — только у источников с включённым бекапом. Если успешных ещё не было,
     * считаем от создания настроек бекапа (у базы — от создания подключения).
     *
     * @return array{0: bool|null, 1: string}
     */
    private function backupMissing(Server|DatabaseConnection $source, ?Backup $lastSuccess, AlertRule $rule): array
    {
        $enabled = $source instanceof Server ? (bool) $source->backupSettings?->enabled : (bool) $source->backup_enabled;
        if (! $enabled) {
            return [false, ''];
        }
        $since = $lastSuccess ? ($lastSuccess->finished_at ?? $lastSuccess->created_at) : ($source instanceof Server ? $source->backupSettings->created_at : $source->created_at);
        $hours = $since ? (int) floor($since->diffInMinutes(now(), true) / 60) : 0;
        $title = ($source instanceof Server ? 'сервера ' : 'базы ').$source->name;

        return [
            $hours > $rule->threshold,
            $lastSuccess ? "Нет успешного бекапа {$title} уже {$hours} ч (последний — {$this->date($since)})" : "Нет ни одного успешного бекапа {$title} уже {$hours} ч",
        ];
    }

    // --- хранилища

    /**
     * Заполненность диска SystemKeeper и Яндекс Диска; у S3 размера нет — такие хранилища пропускаются.
     * Проект не учитывается: хранилища общие.
     *
     * @return list<Observation>
     */
    private function storages(AlertRule $rule): array
    {
        return $this->scope(BackupStorage::query(), $rule, 'storage', byProject: false)->orderBy('id')->get()->map(function (BackupStorage $storage) use ($rule) {
            $disk = $storage->disk();
            if (! $disk || $disk['total'] <= 0) {
                return new Observation('storage', $storage->id, $storage->name, null, null);
            }
            $percent = ($disk['total'] - $disk['free']) / $disk['total'] * 100;

            return new Observation('storage', $storage->id, $storage->name, null, $percent > $rule->threshold,
                "Хранилище {$storage->name} заполнено на ".$this->number($percent).'% (свободно '.Bytes::format($disk['free']).')');
        })->all();
    }

    private function number(float $value, int $digits = 0): string
    {
        $text = number_format($value, $digits, ',', '');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    private function date(?Carbon $date): string
    {
        return $date ? $date->clone()->setTimezone(config('backup.timezone'))->format('d.m H:i') : '—';
    }
}
