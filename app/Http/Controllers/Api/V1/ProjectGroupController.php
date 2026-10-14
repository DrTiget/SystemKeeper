<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectGroupResource;
use App\Models\ProjectGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Группы проектов. Проект попадает в группу полем `group_id` (`POST/PATCH /projects`).
 */
class ProjectGroupController extends Controller
{
    /** Все группы по названию с числом проектов. */
    public function index(): AnonymousResourceCollection
    {
        return ProjectGroupResource::collection(ProjectGroup::query()->withCount('projects')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $group = ProjectGroup::create($this->validated($request));

        return (new ProjectGroupResource($group->loadCount('projects')))->response()->setStatusCode(201);
    }

    public function show(ProjectGroup $projectGroup): ProjectGroupResource
    {
        return new ProjectGroupResource($projectGroup->loadCount('projects'));
    }

    public function update(Request $request, ProjectGroup $projectGroup): ProjectGroupResource
    {
        $projectGroup->update($this->validated($request));

        return new ProjectGroupResource($projectGroup->loadCount('projects'));
    }

    /** Удаляет группу; её проекты остаются без группы. */
    public function destroy(ProjectGroup $projectGroup): Response
    {
        $projectGroup->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255']], attributes: ['name' => 'название']);
    }
}
