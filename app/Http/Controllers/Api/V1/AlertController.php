<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Срабатывания правил: что сломалось сейчас и что ломалось раньше.
 */
class AlertController extends Controller
{
    /**
     * Алерты с пагинацией: `status` (`firing` — идут сейчас, `resolved` — история), `project_id`, `severity`, `per_page`, `page`.
     * Активные — сначала критичные, потом по времени начала; история — по времени решения.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:firing,resolved'],
            'project_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'in:'.implode(',', AlertRule::SEVERITIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $alerts = $this->query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->input('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->input('status') === Alert::RESOLVED,
                fn ($q) => $q->orderByDesc('resolved_at'),
                fn ($q) => $q->orderByRaw("severity = 'critical' desc")->orderByDesc('started_at'))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return AlertResource::collection($alerts);
    }

    /** Сколько алертов активно и сколько из них критичных — для счётчика в меню. */
    public function summary(): JsonResponse
    {
        $firing = Alert::query()->where('status', Alert::FIRING);

        return response()->json(['data' => [
            'firing' => $firing->clone()->count(),
            'critical' => $firing->clone()->where('severity', AlertRule::CRITICAL)->count(),
        ]]);
    }

    /**
     * Взять в работу: повторные уведомления по алерту перестают приходить. Повторное нажатие ничего не меняет;
     * `409` — алерт уже решён.
     */
    public function ack(Request $request, Alert $alert): AlertResource
    {
        abort_unless($alert->isFiring(), 409, 'Алерт уже решён.');

        if (! $alert->acknowledged_at) {
            $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user() instanceof User ? $request->user()->id : null])->save();
        }

        return new AlertResource($this->query()->findOrFail($alert->id));
    }

    private function query()
    {
        return Alert::query()->with(['rule', 'project', 'acknowledger', 'notifications.channel']);
    }
}
