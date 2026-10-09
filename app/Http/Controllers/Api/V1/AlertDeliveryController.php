<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertDeliveryResource;
use App\Models\AlertDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Журнал отправок алертов (только чтение). */
class AlertDeliveryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AlertDelivery::query()->orderByDesc('id');
        foreach (['status', 'event'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }
        foreach (['alert_rule_id', 'alert_channel_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }

        return AlertDeliveryResource::collection($query->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }
}
