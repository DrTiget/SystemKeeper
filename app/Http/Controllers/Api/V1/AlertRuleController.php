<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\RuleRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AlertRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AlertRule::query()->with('channels')->orderBy('name');
        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        return AlertRuleResource::collection($query->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }

    public function store(RuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rule = AlertRule::create(collect($data)->except('channel_ids')->all());
        $rule->channels()->sync($data['channel_ids']);

        return (new AlertRuleResource($rule->refresh()->load('channels')))->response()->setStatusCode(201);
    }

    public function show(AlertRule $alertRule): AlertRuleResource
    {
        return new AlertRuleResource($alertRule->load('channels'));
    }

    public function update(RuleRequest $request, AlertRule $alertRule): AlertRuleResource
    {
        $data = $request->validated();
        $fields = collect($data)->except('channel_ids')->all();
        // Смена типа объекта без нового фильтра сбрасывает его целиком
        if (array_key_exists('target_type', $fields) && $fields['target_type'] === null) {
            $fields['target_id'] = null;
        }
        $alertRule->update($fields);
        if (isset($data['channel_ids'])) {
            $alertRule->channels()->sync($data['channel_ids']);
        }

        return new AlertRuleResource($alertRule->load('channels'));
    }

    public function destroy(AlertRule $alertRule): Response
    {
        $alertRule->delete();

        return response()->noContent();
    }
}
