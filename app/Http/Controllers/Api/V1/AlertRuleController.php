<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\AlertRuleRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Правила алертов: что отслеживать, пороги, область, каналы и напоминания.
 */
class AlertRuleController extends Controller
{
    /** Правила по названию (без пагинации); `project_id` — только правила этого проекта. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['project_id' => ['nullable', 'integer']]);

        $rules = $this->query()
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->orderBy('name')->orderBy('id')->get();

        return $this->respond($rules);
    }

    public function store(AlertRuleRequest $request): JsonResponse
    {
        $rule = AlertRule::create($request->ruleData());
        $rule->channels()->sync($request->validated('channel_ids') ?? []);

        return $this->respond($this->query()->findOrFail($rule->id))->setStatusCode(201);
    }

    public function show(AlertRule $alertRule): JsonResponse
    {
        return $this->respond($this->query()->findOrFail($alertRule->id));
    }

    /** Частичное обновление; `{"enabled": false}` выключает правило (его активные алерты закроются молча). */
    public function update(AlertRuleRequest $request, AlertRule $alertRule): JsonResponse
    {
        $alertRule->update($request->ruleData());
        if ($request->has('channel_ids')) {
            $alertRule->channels()->sync($request->validated('channel_ids') ?? []);
        }

        return $this->respond($this->query()->findOrFail($alertRule->id));
    }

    /** Удалить правило вместе с его алертами. */
    public function destroy(AlertRule $alertRule): Response
    {
        $alertRule->delete();

        return response()->noContent();
    }

    private function query()
    {
        return AlertRule::query()->with(['project', 'channels'])
            ->withCount(['alerts as firing_count' => fn ($q) => $q->where('status', Alert::FIRING)]);
    }

    /** @param AlertRule|Collection<int, AlertRule> $rules */
    private function respond(AlertRule|Collection $rules): JsonResponse
    {
        $list = $rules instanceof AlertRule ? collect([$rules]) : $rules;
        $names = $this->objectNames($list);
        $data = $list->map(fn (AlertRule $r) => (new AlertRuleResource($r))->withObjectNames($names)->resolve())->values();

        return response()->json(['data' => $rules instanceof AlertRule ? $data->first() : $data]);
    }

    /**
     * Названия объектов из targets всех правил — по одному запросу на тип.
     *
     * @param  Collection<int, AlertRule>  $rules
     * @return array<string, string>
     */
    private function objectNames(Collection $rules): array
    {
        $names = [];
        $targets = $rules->flatMap(fn (AlertRule $r) => $r->targets ?? [])->groupBy('type');
        foreach ([
            'server' => Server::class, 'monitor' => Monitor::class, 'database' => DatabaseConnection::class, 'storage' => BackupStorage::class,
        ] as $type => $model) {
            if (isset($targets[$type])) {
                foreach ($model::query()->whereIn('id', $targets[$type]->pluck('id'))->pluck('name', 'id') as $id => $name) {
                    $names["{$type}:{$id}"] = $name;
                }
            }
        }

        return $names;
    }
}
