<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\AccessTokenResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Профиль текущего пользователя: данные, настройки, пароль и сеансы.
 */
class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Частичное обновление: передаются только меняемые поля; settings сливаются с текущими.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->safe()->only(['name', 'email']);

        if ($request->has('settings')) {
            $data['settings'] = array_merge($user->resolvedSettings(), $request->validated('settings'));
        }

        $user->update($data);

        return new UserResource($user);
    }

    /**
     * Смена пароля; все сеансы, кроме текущего, завершаются.
     */
    public function password(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();

        return response()->noContent();
    }

    /**
     * Активные сеансы (токены) пользователя, новые сверху.
     */
    public function tokens(Request $request): AnonymousResourceCollection
    {
        return AccessTokenResource::collection($request->user()->tokens()->latest('id')->get());
    }

    /**
     * Завершить сеанс. Завершение текущего равносильно выходу.
     */
    public function destroyToken(Request $request, int $token): Response
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return response()->noContent();
    }

    /**
     * Завершить все сеансы, кроме текущего.
     */
    public function destroyOtherTokens(Request $request): Response
    {
        $user = $request->user();
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();

        return response()->noContent();
    }
}
