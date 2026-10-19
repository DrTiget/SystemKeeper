<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;

/**
 * Текст уведомления об алерте: заголовок, тело и ссылка на объект в SystemKeeper (по APP_URL).
 */
final class AlertMessage
{
    private const SEVERITY = [AlertRule::WARNING => 'Предупреждение', AlertRule::CRITICAL => 'Критично'];

    private const ICON = [AlertRule::WARNING => '🟠', AlertRule::CRITICAL => '🔴'];

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
    ) {}

    public static function for(Alert $alert, string $event): self
    {
        $severity = self::SEVERITY[$alert->severity] ?? $alert->severity;
        $title = match ($event) {
            AlertNotification::RESOLVED => "✅ Решено: {$alert->message}",
            AlertNotification::REPEAT => "⏰ Не решено: {$alert->message}",
            default => (self::ICON[$alert->severity] ?? '⚠️')." {$severity}: {$alert->message}",
        };

        $tz = config('backup.timezone');
        $lines = [
            'Правило: '.($alert->rule?->name ?? '—'),
            'Объект: '.self::objectLabel($alert->object_type).' «'.$alert->object_name.'»',
            'Началось: '.$alert->started_at->clone()->setTimezone($tz)->format('d.m.Y H:i'),
        ];
        if ($event === AlertNotification::RESOLVED && $alert->resolved_at) {
            $lines[] = 'Решено: '.$alert->resolved_at->clone()->setTimezone($tz)->format('d.m.Y H:i').' (длилось '.self::duration($alert->started_at->diffInMinutes($alert->resolved_at, true)).')';
        }

        return new self($title, implode("\n", $lines), self::url($alert->object_type, $alert->object_id));
    }

    public static function test(string $channelName): self
    {
        return new self('✅ Тестовое сообщение SystemKeeper', "Канал «{$channelName}» настроен, уведомления об алертах будут приходить сюда.", rtrim((string) config('app.url'), '/') ?: null);
    }

    /** Ссылка на объект в интерфейсе */
    public static function url(string $type, int $id): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            return null;
        }

        return $base.match ($type) {
            'server' => "/servers/{$id}",
            'monitor' => "/monitors/{$id}",
            'database' => "/databases/{$id}",
            default => '/storages',
        };
    }

    /** Текст целиком — для Telegram и вебхуков */
    public function text(): string
    {
        return $this->title."\n\n".$this->body.($this->url ? "\n\n{$this->url}" : '');
    }

    private static function objectLabel(string $type): string
    {
        return match ($type) {
            'server' => 'Сервер',
            'monitor' => 'Монитор',
            'database' => 'База данных',
            default => 'Хранилище',
        };
    }

    private static function duration(float $minutes): string
    {
        $minutes = (int) round($minutes);
        if ($minutes < 60) {
            return max(1, $minutes).' мин';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).' ч '.($minutes % 60).' мин';
        }

        return intdiv($minutes, 1440).' д '.intdiv($minutes % 1440, 60).' ч';
    }
}
