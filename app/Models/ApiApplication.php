<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Приложение — внешняя интеграция со статичным API-токеном (guard `app`).
 * Права — по разделам API: `<раздел>:read` и `<раздел>:write` (write включает read), `*` — все разделы.
 */
#[Fillable(['name', 'description', 'is_active', 'scopes'])]
#[Hidden(['token_hash', 'remember_token'])]
class ApiApplication extends Model implements AuthenticatableContract
{
    use Auditable;
    use Authenticatable;

    /** Разделы API, к которым приложению можно дать доступ. */
    public const SECTIONS = [
        'projects' => 'Проекты и группы',
        'servers' => 'Серверы',
        'databases' => 'Базы данных',
        'backups' => 'Бекапы',
        'storages' => 'Диски и облака',
        'monitors' => 'Аптайм',
        'metrics' => 'Ресурсы серверов',
        'alerts' => 'Алерты',
        'system' => 'Система',
        'audit' => 'Журнал действий',
    ];

    public const TOKEN_PREFIX = 'ska_';

    /** Отметку «последнее использование» пишем не чаще раза в минуту. */
    private const TOUCH_EVERY = 60;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @var list<string> Не пишутся в журнал как изменения. */
    public array $auditIgnore = ['last_used_at', 'last_used_ip', 'token_hash', 'token_prefix', 'token_created_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'scopes' => 'array',
            'token_created_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return list<string> все допустимые значения прав */
    public static function availableScopes(): array
    {
        $scopes = ['*:read', '*:write'];
        foreach (array_keys(self::SECTIONS) as $section) {
            $scopes[] = "{$section}:read";
            $scopes[] = "{$section}:write";
        }

        return $scopes;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Может ли приложение читать (`$write = false`) или менять раздел. */
    public function allows(string $section, bool $write): bool
    {
        $scopes = $this->scopes ?? [];
        $levels = $write ? ['write'] : ['read', 'write'];
        foreach ($levels as $level) {
            if (in_array("{$section}:{$level}", $scopes, true) || in_array("*:{$level}", $scopes, true)) {
                return true;
            }
        }

        return false;
    }

    /** Выпустить новый токен (прежний перестаёт работать). Возвращает токен открытым текстом — показать один раз. */
    public function issueToken(): string
    {
        $token = self::TOKEN_PREFIX.Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 10),
            'token_created_at' => now(),
        ])->save();

        return $token;
    }

    /** Отозвать токен: приложение больше не авторизуется, пока не выпустят новый. */
    public function revokeToken(): void
    {
        $this->forceFill(['token_hash' => null, 'token_prefix' => null, 'token_created_at' => null])->save();
    }

    /** Активное приложение по открытому токену; отмечает последнее использование. */
    public static function findByToken(?string $token, ?string $ip = null): ?self
    {
        if (! $token || ! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $app = self::query()->where('token_hash', hash('sha256', $token))->where('is_active', true)->first();
        if ($app && ($app->last_used_at === null || $app->last_used_at->diffInSeconds(now()) >= self::TOUCH_EVERY || $app->last_used_ip !== $ip)) {
            $app->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
        }

        return $app;
    }
}
