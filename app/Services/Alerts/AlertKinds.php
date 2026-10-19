<?php

namespace App\Services\Alerts;

/**
 * Типы правил алертов: к каким объектам относятся и какой у них порог. Зеркало ALERT_KINDS из frontend/src/api/alerts.ts.
 */
class AlertKinds
{
    public const SERVER_CPU = 'server_cpu';

    public const SERVER_MEMORY = 'server_memory';

    public const SERVER_DISK = 'server_disk';

    public const SERVER_LOAD = 'server_load';

    public const SERVER_UNREACHABLE = 'server_unreachable';

    public const MONITOR_DOWN = 'monitor_down';

    public const MONITOR_SSL = 'monitor_ssl';

    public const BACKUP_FAILED = 'backup_failed';

    public const BACKUP_MISSING = 'backup_missing';

    public const STORAGE_FULL = 'storage_full';

    /** Типы объектов */
    public const OBJECT_TYPES = ['server', 'monitor', 'database', 'storage'];

    /**
     * Вид → типы объектов и границы порога (null — порога нет).
     *
     * @var array<string, array{objects: list<string>, threshold: array{min: float, max: float}|null}>
     */
    private const KINDS = [
        self::SERVER_CPU => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_MEMORY => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_DISK => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_LOAD => ['objects' => ['server'], 'threshold' => ['min' => 0.1, 'max' => 100]],
        self::SERVER_UNREACHABLE => ['objects' => ['server'], 'threshold' => null],
        self::MONITOR_DOWN => ['objects' => ['monitor'], 'threshold' => null],
        self::MONITOR_SSL => ['objects' => ['monitor'], 'threshold' => ['min' => 1, 'max' => 90]],
        self::BACKUP_FAILED => ['objects' => ['server', 'database'], 'threshold' => null],
        self::BACKUP_MISSING => ['objects' => ['server', 'database'], 'threshold' => ['min' => 1, 'max' => 720]],
        self::STORAGE_FULL => ['objects' => ['storage'], 'threshold' => ['min' => 1, 'max' => 100]],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::KINDS);
    }

    /** @return list<string> типы объектов, к которым применим вид */
    public static function objects(string $kind): array
    {
        return self::KINDS[$kind]['objects'] ?? [];
    }

    /** @return array{min: float, max: float}|null */
    public static function threshold(string $kind): ?array
    {
        return self::KINDS[$kind]['threshold'] ?? null;
    }
}
