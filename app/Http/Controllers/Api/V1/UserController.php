<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Пользователи системы. Доступно только администраторам (gate manage-users).
 */
class UserController extends Controller
{
    /**
     * Список пользователей по имени; `search` — поиск по имени и email.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when($request->string('search')->trim()->value(), fn ($q, $s) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"),
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * Частичное обновление. При смене пароля все сеансы пользователя завершаются.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        if ($request->has('password') && ! $user->is($request->user())) {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }

    /**
     * Удаление пользователя вместе с его сеансами. Себя удалить нельзя.
     */
    public function destroy(Request $request, User $user): Response
    {
        abort_if($user->is($request->user()), 409, 'Нельзя удалить самого себя.');

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
