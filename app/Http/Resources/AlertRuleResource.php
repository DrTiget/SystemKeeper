<?php

namespace App\Http\Resources;

use App\Models\AlertRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Правило с названиями объектов. Названия передаются списком через withObjectNames() — чтобы не искать их в БД по одному.
 *
 * @mixin AlertRule
 */
class AlertRuleResource extends JsonResource
{
    /** @var array<string, string> «тип:id» → название */
    private array $objectNames = [];

    /** @param array<string, string> $names */
    public function withObjectNames(array $names): static
    {
        $this->objectNames = $names;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            /** server_cpu, server_memory, server_disk, server_load, server_unreachable, monitor_down, monitor_ssl, backup_failed, backup_missing, storage_full */
            'kind' => $this->kind,
            'severity' => $this->severity,
            /** Порог: % для CPU/памяти/диска/хранилища, нагрузка на ядро для load, дни для SSL, часы для backup_missing; null — у видов без порога */
            'threshold' => $this->threshold,
            /** Сколько минут условие должно держаться, прежде чем сработать (0 — сразу) */
            'duration' => $this->duration,
            'project_id' => $this->project_id,
            'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null,
            /** Выбранные объекты; пусто — все объекты нужного типа в области */
            'targets' => $this->targets ?? [],
            /** Выбранные объекты с названиями */
            'objects' => collect($this->targets ?? [])->map(fn ($t) => [
                'type' => $t['type'],
                'id' => $t['id'],
                'name' => $this->objectNames["{$t['type']}:{$t['id']}"] ?? "Удалён (#{$t['id']})",
            ])->values()->all(),
            'channel_ids' => $this->channels->pluck('id')->values()->all(),
            'channels' => $this->channels->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])->values()->all(),
            /** Напоминать каждые N минут, пока не решено; null — один раз */
            'repeat_every' => $this->repeat_every,
            'notify_resolved' => $this->notify_resolved,
            /** Сколько срабатываний по правилу активно сейчас */
            'firing_count' => (int) ($this->firing_count ?? $this->alerts()->where('status', 'firing')->count()),
            'last_fired_at' => $this->last_fired_at,
            'created_at' => $this->created_at,
        ];
    }
}
