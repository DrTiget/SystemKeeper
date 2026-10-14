<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Проекты — группы серверов и баз данных. Состав проекта задаётся полем `project_id` у сервера и базы;
 * списки — `GET /servers?project_id=` и `GET /databases?project_id=`.
 */
class ProjectController extends Controller
{
    /** Проекты по названию с числом серверов и баз; `search` — по названию и описанию. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ProjectResource::collection(Project::query()
            ->withCount(['servers', 'databases'])
            ->when($request->string('search')->trim()->value(), fn ($q, $s) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"),
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $project = Project::create($this->validated($request, true));

        return (new ProjectResource($project->loadCount(['servers', 'databases'])))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->loadCount(['servers', 'databases']));
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $project->update($this->validated($request, false));

        return new ProjectResource($project->loadCount(['servers', 'databases']));
    }

    /** Удаляет проект; его серверы и базы данных остаются без проекта. */
    public function destroy(Project $project): Response
    {
        $project->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], attributes: ['name' => 'название', 'description' => 'описание']);
    }
}
