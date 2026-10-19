<?php

namespace App\Services\Audit;

use App\Models\ApiApplication;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Журнал действий: запись событий с актором (пользователь, приложение или система) и маскировкой секретов.
 * Ошибка записи журнала никогда не ломает само действие.
 */
class Audit
{
    public const MASK = '***';

    /** Ключи, значения которых считаются секретами (в том числе во вложенных массивах). */
    private const SECRET_KEYS = '/pass(word|phrase)?|secret|token|private_?key|api_?key|credential|authorization|cookie|signature/i';

    /** Поля, которые не пишем в изменения. */
    private const IGNORED = ['created_at', 'updated_at', 'remember_token'];

    /** Было ли в текущем HTTP-запросе записано событие (см. App\Http\Middleware\AuditRequests). */
    private bool $recorded = false;

    public function resetRequest(): void
    {
        $this->recorded = false;
    }

    public function recordedInRequest(): bool
    {
        return $this->recorded;
    }

    /** Пользователь или приложение, выполняющие текущий HTTP-запрос; null — фоновая задача или гость. */
    public function actor(): User|ApiApplication|null
    {
        try {
            $user = Auth::hasUser() ? Auth::user() : null;
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User || $user instanceof ApiApplication ? $user : null;
    }

    /**
     * @param  array<string, mixed>|null  $changes  изменения/подробности; секреты маскируются
     * @param  User|ApiApplication|false|null  $actor  null — текущий актор запроса (или система), false — система
     */
    public function record(string $event, ?Model $subject = null, ?array $changes = null, User|ApiApplication|false|null $actor = null, ?string $label = null): ?AuditLog
    {
        $actor = $actor === false ? null : ($actor ?? $this->actor());
        $http = $actor !== null || in_array($event, ['auth.login', 'auth.login_failed'], true);
        $request = request();

        try {
            $log = AuditLog::create([
                'actor_type' => match (true) {
                    $actor instanceof User => AuditLog::ACTOR_USER,
                    $actor instanceof ApiApplication => AuditLog::ACTOR_APPLICATION,
                    default => AuditLog::ACTOR_SYSTEM,
                },
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
                'event' => $event,
                'subject_type' => $subject ? self::subjectType($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label ?? ($subject ? self::label($subject) : null),
                'changes' => $changes === null ? null : self::mask($changes, $subject),
                'ip' => $http ? $request->ip() : null,
                'user_agent' => $http ? Str::limit((string) $request->userAgent(), 500, '') ?: null : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $this->recorded = true;

        return $log;
    }

    /** Изменение модели через API (created/updated/deleted) — пишется, только когда есть актор запроса. */
    public function recordModel(string $event, Model $model): void
    {
        if ($this->actor() === null) {
            return;
        }

        $ignored = array_merge(self::IGNORED, property_exists($model, 'auditIgnore') ? $model->auditIgnore : []);
        $changes = match ($event) {
            'updated' => (function () use ($model, $ignored) {
                $new = array_diff_key($model->getChanges(), array_flip($ignored));
                if ($new === []) {
                    return null;
                }
                $old = array_intersect_key($model->getRawOriginal(), $new);

                return ['old' => self::castAll($model, $old), 'new' => self::castAll($model, $new)];
            })(),
            default => ['attributes' => self::castAll($model, array_diff_key($model->getAttributes(), array_flip($ignored)))],
        };
        if ($event === 'updated' && $changes === null) {
            return;
        }

        $this->record(self::subjectType($model).'.'.$event, $model, $changes);
    }

    /** Короткое имя типа объекта: морф-алиас или snake_case имени класса. */
    public static function subjectType(Model $model): string
    {
        return array_search($model::class, Relation::morphMap(), true) ?: Str::snake(class_basename($model));
    }

    public static function label(Model $model): string
    {
        foreach (['name', 'email', 'title'] as $field) {
            if (is_string($model->getAttribute($field)) && $model->getAttribute($field) !== '') {
                return Str::limit($model->getAttribute($field), 250);
            }
        }

        return '#'.$model->getKey();
    }

    /**
     * Замаскировать секреты: поля модели из hidden и с шифрованием целиком, остальные — по имени ключа на любой глубине.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mask(array $data, ?Model $model = null): array
    {
        $secretFields = [];
        if ($model) {
            $secretFields = $model->getHidden();
            foreach ($model->getCasts() as $field => $cast) {
                if (str_starts_with((string) $cast, 'encrypted') || $cast === 'hashed') {
                    $secretFields[] = $field;
                }
            }
        }

        $walk = function (array $data, bool $top) use (&$walk, $secretFields): array {
            foreach ($data as $key => $value) {
                $secret = is_string($key) && (preg_match(self::SECRET_KEYS, $key) === 1 || ($top && in_array($key, $secretFields, true)));
                if ($secret) {
                    $data[$key] = $value === null || $value === '' ? $value : self::MASK;
                } elseif (in_array($key, ['old', 'new', 'attributes'], true) && $top && is_array($value)) {
                    $data[$key] = $walk($value, true);
                } elseif (is_array($value)) {
                    $data[$key] = $walk($value, false);
                }
            }

            return $data;
        };

        return $walk($data, true);
    }

    /**
     * Сырые значения → читаемые (JSON-поля как массивы); зашифрованные не расшифровываем.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function castAll(Model $model, array $raw): array
    {
        $casts = $model->getCasts();
        foreach ($raw as $key => $value) {
            $cast = (string) ($casts[$key] ?? '');
            if (is_string($value) && in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
                $raw[$key] = json_decode($value, true);
            } elseif ($cast === 'boolean' && $value !== null) {
                $raw[$key] = (bool) $value;
            }
        }

        return $raw;
    }
}
