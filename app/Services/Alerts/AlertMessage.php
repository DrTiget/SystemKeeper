<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use Illuminate\Support\Carbon;

/**
 * Уведомление об алерте в едином виде для всех событий (сработал, напоминание, решено, тест): данные события и их
 * отрисовка для каналов — письмо (view `mail.alert`), Telegram (HTML), вебхук (текст и Markdown).
 * Пользовательские данные (названия объектов, правил, тексты ошибок) экранируются под каждый формат.
 */
final class AlertMessage
{
    private const SEVERITY = [AlertRule::WARNING => 'Предупреждение', AlertRule::CRITICAL => 'Критично'];

    private const ICON = [AlertRule::WARNING => '🟠', AlertRule::CRITICAL => '🔴'];

    private const COLOR = ['warning' => '#d97706', 'critical' => '#dc2626', 'resolved' => '#16a34a', 'info' => '#2563eb'];

    private const TEST_NOTE = 'Это тестовое уведомление — реального сбоя нет.';

    /** Длина значений в Telegram: сообщение целиком не должно превысить 4096 знаков (HTML обрезать нельзя) */
    private const TELEGRAM_VALUE = 300;

    private const TELEGRAM_MESSAGE = 1500;

    /**
     * @param  string  $event  firing, repeat или resolved
     * @param  ?string  $severity  warning или critical; null — у проверки канала
     * @param  bool  $test  тестовое уведомление: оформляется как настоящее, но помечено «Тестовое уведомление»
     */
    public function __construct(
        public readonly string $event,
        public readonly ?string $severity,
        public readonly string $message,
        public readonly ?string $objectType = null,
        public readonly ?string $objectName = null,
        public readonly ?string $project = null,
        public readonly ?string $ruleName = null,
        public readonly ?string $ruleUrl = null,
        public readonly ?string $condition = null,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $resolvedAt = null,
        public readonly ?string $url = null,
        public readonly bool $test = false,
        public readonly ?string $note = null,
    ) {}

    public static function for(Alert $alert, string $event): self
    {
        $rule = $alert->rule;

        return new self(
            event: $event,
            severity: $alert->severity,
            message: $alert->message,
            objectType: $alert->object_type,
            objectName: $alert->object_name,
            project: $alert->project?->name,
            ruleName: $rule?->name,
            ruleUrl: $rule ? self::ruleUrl($rule->id) : null,
            condition: $rule ? AlertKinds::describe($rule->kind, $rule->threshold, $rule->duration) : null,
            startedAt: $alert->started_at,
            resolvedAt: $event === AlertNotification::RESOLVED ? $alert->resolved_at : null,
            url: self::url($alert->object_type, $alert->object_id),
        );
    }

    /** Пример уведомления по правилу: сработавшее, на объекте из области правила (или примере). Ссылка ведёт на правило. */
    public static function forRule(AlertRule $rule, string $objectType, string $objectName, ?string $project, string $message): self
    {
        return new self(
            event: AlertNotification::FIRING,
            severity: $rule->severity,
            message: $message,
            objectType: $objectType,
            objectName: $objectName,
            project: $project,
            ruleName: $rule->name,
            ruleUrl: self::ruleUrl($rule->id),
            condition: AlertKinds::describe($rule->kind, $rule->threshold, $rule->duration),
            startedAt: now(),
            url: self::ruleUrl($rule->id),
            test: true,
        );
    }

    /** Проверка канала (кнопка «Отправить тестовое сообщение» у канала) */
    public static function test(string $channelName): self
    {
        return new self(AlertNotification::FIRING, null, "Канал «{$channelName}» настроен", url: self::base() ?: null, test: true, note: 'Уведомления об алертах будут приходить сюда.');
    }

    /** Проверка почты («Настройки → Уведомления») */
    public static function mailCheck(): self
    {
        return new self(AlertNotification::FIRING, null, 'Почта настроена', url: self::base() ?: null, test: true, note: 'Уведомления об алертах будут приходить на этот адрес.');
    }

    // --- ссылки

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Ссылка на объект в интерфейсе */
    public static function url(string $type, int $id): ?string
    {
        $base = self::base();
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

    /** Ссылка на правило (форма редактирования, где его можно отключить) */
    public static function ruleUrl(int $id): ?string
    {
        $base = self::base();

        return $base === '' ? null : "{$base}/alerts/rules/{$id}";
    }

    // --- данные события

    /** resolved — зелёный; иначе по важности; critical, warning; info — проверка канала без важности */
    public function status(): string
    {
        if ($this->event === AlertNotification::RESOLVED && ! $this->test) {
            return 'resolved';
        }

        return $this->severity ?? 'info';
    }

    public function color(): string
    {
        return self::COLOR[$this->status()] ?? self::COLOR['info'];
    }

    public function severityLabel(): ?string
    {
        return $this->severity ? (self::SEVERITY[$this->severity] ?? $this->severity) : null;
    }

    /** Название события: «Критично», «Предупреждение», «Не решено», «Решено», «Тестовое уведомление» */
    public function label(): string
    {
        return match (true) {
            $this->test => 'Тестовое уведомление',
            $this->status() === 'resolved' => 'Решено',
            $this->event === AlertNotification::REPEAT => 'Не решено',
            default => $this->severityLabel() ?? 'Уведомление',
        };
    }

    /** 🔴 критично, 🟠 предупреждение, ✅ решено, 🧪 тест */
    public function icon(): string
    {
        return match (true) {
            $this->test => '🧪',
            $this->status() === 'resolved' => '✅',
            default => self::ICON[$this->severity] ?? '🔔',
        };
    }

    /** Метка в теме письма: КРИТИЧНО, ПРЕДУПРЕЖДЕНИЕ, КРИТИЧНО · НЕ РЕШЕНО, РЕШЕНО, ТЕСТ */
    public function tag(): string
    {
        $severity = mb_strtoupper((string) $this->severityLabel());

        return match (true) {
            $this->test => 'ТЕСТ',
            $this->status() === 'resolved' => 'РЕШЕНО',
            $this->event === AlertNotification::REPEAT => trim($severity.' · НЕ РЕШЕНО', ' ·'),
            default => $severity ?: 'УВЕДОМЛЕНИЕ',
        };
    }

    /** Тип объекта и название: «Сервер web-1» */
    public function objectShort(): ?string
    {
        return $this->objectName === null ? null : trim(self::objectLabel((string) $this->objectType).' '.$this->objectName);
    }

    /** «[КРИТИЧНО] Сервер web-1: CPU 97%» */
    public function subject(): string
    {
        $object = $this->objectShort();

        return "[{$this->tag()}] ".($object ? "{$object}: " : '').$this->message;
    }

    /** Заголовок: «Критично: Сервер web-1», «Решено: Сервер web-1», «Тестовое уведомление» */
    public function heading(): string
    {
        $object = $this->objectShort();

        return $this->test || ! $object ? $this->label() : "{$this->label()}: {$object}";
    }

    /** Заголовок вебхука: «🔴 Критично: CPU 97%» */
    public function title(): string
    {
        return "{$this->icon()} {$this->label()}: {$this->message}";
    }

    /** Пояснение у тестового уведомления */
    public function testNote(): ?string
    {
        return $this->test ? ($this->note ?? self::TEST_NOTE) : null;
    }

    /**
     * Детали события.
     *
     * @param  bool  $all  false — без условия и важности (короткий вид для Telegram)
     * @return list<array{label: string, value: string, url: ?string}>
     */
    public function rows(bool $all = true): array
    {
        $tz = config('backup.timezone');
        $date = fn (Carbon $d) => $d->clone()->setTimezone($tz)->format('d.m.Y H:i');
        $rows = [];

        if ($this->objectName !== null) {
            $rows[] = ['label' => 'Объект', 'value' => self::objectLabel((string) $this->objectType).' «'.$this->objectName.'»', 'url' => null];
        }
        if ($this->project !== null) {
            $rows[] = ['label' => 'Проект', 'value' => $this->project, 'url' => null];
        }
        if ($this->ruleName !== null) {
            $rows[] = ['label' => 'Правило', 'value' => $this->ruleName, 'url' => $this->ruleUrl];
        }
        if ($all && $this->condition !== null) {
            $rows[] = ['label' => 'Условие', 'value' => $this->condition, 'url' => null];
        }
        if ($all && $this->severityLabel() !== null) {
            $rows[] = ['label' => 'Важность', 'value' => $this->severityLabel(), 'url' => null];
        }
        if ($this->startedAt) {
            $rows[] = ['label' => 'Начало', 'value' => $date($this->startedAt), 'url' => null];
        }
        if ($this->resolvedAt) {
            $rows[] = ['label' => 'Решено', 'value' => $date($this->resolvedAt), 'url' => null];
        }
        if ($this->startedAt && ! $this->test && $this->event !== AlertNotification::FIRING) {
            $rows[] = ['label' => 'Длительность', 'value' => self::duration($this->startedAt->diffInMinutes($this->resolvedAt ?? now(), true)), 'url' => null];
        }

        return $rows;
    }

    // --- отрисовка

    /** Простой текст — вебхук, письмо без HTML */
    public function text(): string
    {
        $lines = ["{$this->icon()} {$this->heading()}", $this->message, ''];
        foreach ($this->rows() as $row) {
            $lines[] = "{$row['label']}: {$row['value']}";
        }
        if ($note = $this->testNote()) {
            array_push($lines, '', $note);
        }
        if ($this->url) {
            array_push($lines, '', "Открыть: {$this->url}");
        }
        if ($this->ruleUrl && $this->ruleName !== null) {
            $lines[] = "Правило (отключить можно здесь): {$this->ruleUrl}";
        }

        return implode("\n", $lines);
    }

    /** Markdown — для вебхуков (Slack, n8n и т. п.); пользовательские данные экранированы */
    public function markdown(): string
    {
        $lines = ["**{$this->icon()} ".self::md($this->heading()).'**', self::md($this->message), ''];
        foreach ($this->rows() as $row) {
            $value = self::md($row['value']);
            $lines[] = "- **{$row['label']}:** ".($row['url'] ? '['.$value.']('.self::mdUrl($row['url']).')' : $value);
        }
        if ($note = $this->testNote()) {
            array_push($lines, '', '_'.self::md($note).'_');
        }
        if ($this->url) {
            array_push($lines, '', '[Открыть в SystemKeeper]('.self::mdUrl($this->url).')');
        }

        return implode("\n", $lines);
    }

    /** HTML для Telegram (parse_mode=HTML): поддерживаются только <b>, <i>, <a>; всё остальное экранировано */
    public function telegramHtml(): string
    {
        $lines = ["{$this->icon()} <b>".self::html($this->heading(), self::TELEGRAM_VALUE).'</b>', self::html($this->message, self::TELEGRAM_MESSAGE), ''];
        foreach ($this->rows(false) as $row) {
            $value = self::html($row['value'], self::TELEGRAM_VALUE);
            $lines[] = "{$row['label']}: ".($row['url'] ? '<a href="'.self::html($row['url'], 500, true).'">'.$value.'</a>' : $value);
        }
        if ($note = $this->testNote()) {
            array_push($lines, '', '<i>'.self::html($note, self::TELEGRAM_VALUE).'</i>');
        }
        if ($this->url) {
            array_push($lines, '', '<a href="'.self::html($this->url, 500, true).'">Открыть в SystemKeeper</a>');
        }

        return implode("\n", $lines);
    }

    // --- экранирование

    /** Экранирование для Telegram HTML: &, <, > (и кавычки внутри атрибута); длинное значение обрезается до экранирования */
    public static function html(string $text, int $limit, bool $attribute = false): string
    {
        $text = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;

        return htmlspecialchars($text, ($attribute ? ENT_QUOTES : ENT_NOQUOTES) | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Экранирование для Markdown: служебные знаки получают обратную косую */
    public static function md(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]<>~|&])/u', '\\\\$1', $text);
    }

    /** Адрес внутри скобок Markdown-ссылки: пробелы и скобки кодируются */
    private static function mdUrl(string $url): string
    {
        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E']);
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
