<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\AlertRuleRequest;
use App\Http\Requests\Alert\ApplySuggestionsRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertRule;
use App\Services\Alerts\RuleSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * «Рекомендуемые правила»: готовый базовый набор алертов для того, что есть в системе.
 */
class AlertRuleSuggestionController extends Controller
{
    /** Что можно добавить сейчас → `{data: AlertSuggestion[]}`; пусто, если всё уже покрыто или нечего отслеживать. */
    public function index(RuleSuggestions $suggestions): JsonResponse
    {
        return response()->json(['data' => $suggestions->all()]);
    }

    /** Создаёт выбранные правила так же, как POST /alert-rules (та же валидация); `201 {data: AlertRule[]}`. */
    public function apply(ApplySuggestionsRequest $request, RuleSuggestions $suggestions): JsonResponse
    {
        $channelIds = $request->channelIds();

        $rules = DB::transaction(fn () => collect($request->keys())->map(function (string $key) use ($suggestions, $channelIds) {
            $rule = AlertRule::create($this->validatedRule($suggestions->fields($key) + ['enabled' => true, 'channel_ids' => $channelIds])->ruleData());
            $rule->channels()->sync($channelIds);

            return $rule;
        }));

        $rules = AlertRule::query()->with(['project', 'channels'])->withCount('alerts as firing_count')
            ->whereIn('id', $rules->pluck('id'))->orderBy('id')->get();

        return response()->json([
            'data' => $rules->map(fn (AlertRule $r) => (new AlertRuleResource($r))->resolve())->values(),
        ], 201);
    }

    /** Тело проходит ту же проверку, что и создание правила вручную. */
    private function validatedRule(array $body): AlertRuleRequest
    {
        $request = AlertRuleRequest::create('/api/v1/alert-rules', 'POST', $body);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }
}
