<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\Audit\AuditSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Журнал действий: изменения через API, вход и выход, системные события (бекапы, алерты, синхронизация).
 * Читают администраторы и приложения с правом `audit:read`; срок хранения настраивает администратор.
 */
class AuditLogController extends Controller
{
    /**
     * Записи от новых к старым. Фильтры: `actor_type` (user|application|system), `actor_id`, `event` (точно или префикс
     * с `.*`, например `backup.*`), `subject_type`, `subject_id`, `from`/`to` (дата или дата-время), `search` — по имени
     * актора и объекта.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'actor_type' => ['nullable', Rule::in([AuditLog::ACTOR_USER, AuditLog::ACTOR_APPLICATION, AuditLog::ACTOR_SYSTEM])],
            'actor_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = AuditLog::query()
            ->when($request->input('actor_type'), fn ($q, $v) => $q->where('actor_type', $v))
            ->when($request->input('actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->when($request->input('event'), fn ($q, $v) => str_ends_with($v, '.*')
                ? $q->where('event', 'like', str_replace(['%', '_'], ['\%', '\_'], substr($v, 0, -1)).'%')
                : $q->where('event', $v))
            ->when($request->input('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->input('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->date('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->date('to'), fn ($q, $v) => $q->where('created_at', '<=', strlen($request->input('to')) <= 10 ? $v->endOfDay() : $v))
            ->when($request->string('search')->trim()->value(), fn ($q, $s) => $q->where(
                fn ($q) => $q->where('actor_name', 'like', "%{$s}%")->orWhere('subject_label', 'like', "%{$s}%"),
            ))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog);
    }

    /** Настройки журнала (администратор): срок хранения в днях. */
    public function settings(AuditSettings $settings): JsonResponse
    {
        return response()->json(['data' => ['retention_days' => $settings->retentionDays()]]);
    }

    /** Изменить срок хранения: 1–3650 дней; старые записи удаляет `audit:prune` раз в сутки. */
    public function updateSettings(Request $request, AuditSettings $settings): JsonResponse
    {
        $data = $request->validate(['retention_days' => ['required', 'integer', 'min:1', 'max:3650']]);
        $settings->setRetentionDays($data['retention_days']);

        return response()->json(['data' => ['retention_days' => $settings->retentionDays()]]);
    }
}
