<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query()->with('monitor')->orderByRaw('resolved_at is null desc')->orderByDesc('started_at')->orderByDesc('id');

        if ($request->filled('monitor_id')) {
            $query->where('monitor_id', $request->integer('monitor_id'));
        }
        MonitorController::filterIncidents($request, $query);

        return IncidentResource::collection($query->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }

    public function show(Incident $incident): IncidentResource
    {
        return new IncidentResource($incident->load('monitor'));
    }
}
