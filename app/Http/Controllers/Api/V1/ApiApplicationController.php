<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiApplication\ApiApplicationRequest;
use App\Http\Resources\ApiApplicationResource;
use App\Models\ApiApplication;
use App\Services\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Приложения — интеграции со статичным API-токеном. Управляют только администраторы.
 *
 * Токен показывается один раз — в ответе на создание и перевыпуск (`token`), в базе хранится только SHA-256.
 * Запросы приложения: `Authorization: Bearer <token>` или `X-Api-Key: <token>`.
 */
class ApiApplicationController extends Controller
{
    /** Список приложений по названию. */
    public function index(): AnonymousResourceCollection
    {
        return ApiApplicationResource::collection(ApiApplication::query()->with('creator')->orderBy('name')->get());
    }

    /**
     * Разделы API и допустимые права: `<раздел>:read`, `<раздел>:write` (write включает read), `*:read`/`*:write` — все разделы.
     */
    public function scopes(): JsonResponse
    {
        return response()->json(['data' => [
            'sections' => collect(ApiApplication::SECTIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'scopes' => ApiApplication::availableScopes(),
        ]]);
    }

    /** Создать приложение и выпустить токен (в ответе `token` — показать один раз). */
    public function store(ApiApplicationRequest $request): JsonResponse
    {
        $app = new ApiApplication($request->validated());
        $app->created_by = $request->user()->id;
        $app->save();
        $token = $app->issueToken();

        return (new ApiApplicationResource($app->load('creator')))->withToken($token)->response()->setStatusCode(201);
    }

    public function show(ApiApplication $application): ApiApplicationResource
    {
        return new ApiApplicationResource($application->load('creator'));
    }

    /** Частичное обновление: название, описание, активность, права. */
    public function update(ApiApplicationRequest $request, ApiApplication $application): ApiApplicationResource
    {
        $application->update($request->validated());

        return new ApiApplicationResource($application->load('creator'));
    }

    public function destroy(ApiApplication $application): Response
    {
        $application->delete();

        return response()->noContent();
    }

    /** Перевыпустить токен: прежний сразу перестаёт работать, новый — в `token` (показать один раз). */
    public function regenerate(ApiApplication $application, Audit $audit): ApiApplicationResource
    {
        $token = $application->issueToken();
        $audit->record('api_application.token_issued', $application, ['token_prefix' => $application->token_prefix]);

        return (new ApiApplicationResource($application->load('creator')))->withToken($token);
    }

    /** Отозвать токен: приложение не авторизуется, пока не выпустят новый. */
    public function revoke(ApiApplication $application, Audit $audit): ApiApplicationResource
    {
        $application->revokeToken();
        $audit->record('api_application.token_revoked', $application);

        return new ApiApplicationResource($application->load('creator'));
    }
}
