<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\ApiApplication;
use App\Models\Backup;
use App\Models\StorageSync;
use App\Models\User;
use App\Services\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Приложения (guard `app` по статичному токену) и системные события журнала действий.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Audit::class);
    }

    public function boot(): void
    {
        // Токен приложения: Authorization: Bearer ska_… или X-Api-Key: ska_…
        Auth::viaRequest('api-application', fn (Request $request) => ApiApplication::findByToken(
            $request->bearerToken() ?: $request->header('X-Api-Key'),
            $request->ip(),
        ));

        // Журнал действий: администраторам и приложениям с правом audit:read
        Gate::define('view-audit-log', fn (User|ApiApplication $actor) => $actor instanceof User ? $actor->is_admin : $actor->allows('audit', false));

        $audit = fn (): Audit => $this->app->make(Audit::class);

        // Бекапы: постановка в очередь (кем — пользователь, приложение, расписание или хук), старт и результат
        Backup::created(function (Backup $backup) use ($audit) {
            $audit()->record('backup.queued', $backup, ['trigger' => $backup->trigger, 'source' => $backup->source_type.':'.$backup->source_id], label: self::backupLabel($backup));
        });
        Backup::updated(function (Backup $backup) use ($audit) {
            if ($backup->wasChanged('status') && in_array($backup->status, [Backup::RUNNING, Backup::SUCCESS, Backup::FAILED], true)) {
                $details = array_filter(['trigger' => $backup->trigger, 'size' => $backup->size, 'error' => $backup->status === Backup::FAILED ? $backup->error : null], fn ($v) => $v !== null);
                $audit()->record('backup.'.($backup->status === Backup::RUNNING ? 'started' : $backup->status), $backup, $details, false, self::backupLabel($backup));
            }
        });
        Backup::deleted(fn (Backup $backup) => $audit()->record('backup.deleted', $backup, label: self::backupLabel($backup)));

        // Алерты: срабатывание и решение — система, подтверждение — пользователь или приложение
        Alert::created(fn (Alert $alert) => $audit()->record('alert.fired', $alert, ['severity' => $alert->severity, 'message' => $alert->message], false, $alert->object_name));
        Alert::updated(function (Alert $alert) use ($audit) {
            if ($alert->wasChanged('status') && $alert->status === Alert::RESOLVED) {
                $audit()->record('alert.resolved', $alert, ['message' => $alert->message], false, $alert->object_name);
            }
            if ($alert->wasChanged('acknowledged_at') && $alert->acknowledged_at !== null) {
                $audit()->record('alert.acknowledged', $alert, label: $alert->object_name);
            }
        });

        // Синхронизация хранилища: запуск и итог
        StorageSync::created(fn (StorageSync $sync) => $audit()->record('storage_sync.queued', $sync));
        StorageSync::updated(function (StorageSync $sync) use ($audit) {
            if ($sync->wasChanged('status') && in_array($sync->status, [StorageSync::SUCCESS, StorageSync::FAILED], true)) {
                $audit()->record('storage_sync.'.$sync->status, $sync, array_filter(['done' => $sync->done, 'failed' => $sync->failed, 'error' => $sync->error], fn ($v) => $v !== null), false);
            }
        });
    }

    private static function backupLabel(Backup $backup): string
    {
        return ($backup->source?->name ?? $backup->source_type.' #'.$backup->source_id).' — бекап #'.$backup->id;
    }
}
