<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alert\ChannelRequest;
use App\Http\Resources\AlertChannelResource;
use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessageFactory;
use App\Services\Alerts\AlertSkippedException;
use App\Services\Alerts\ChannelSenderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

class AlertChannelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AlertChannelResource::collection(AlertChannel::query()->orderBy('name')->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }

    public function store(ChannelRequest $request): JsonResponse
    {
        $channel = AlertChannel::create($request->validated() + ['settings' => []]);

        return (new AlertChannelResource($channel->refresh()))->response()->setStatusCode(201);
    }

    public function show(AlertChannel $alertChannel): AlertChannelResource
    {
        return new AlertChannelResource($alertChannel);
    }

    public function update(ChannelRequest $request, AlertChannel $alertChannel): AlertChannelResource
    {
        $data = $request->validated();
        if (isset($data['settings'])) {
            // Частичное обновление: не переданные поля (в т.ч. секрет) сохраняются
            $data['settings'] = array_merge($alertChannel->settings ?? [], $data['settings']);
        }
        $alertChannel->update($data);

        return new AlertChannelResource($alertChannel);
    }

    public function destroy(AlertChannel $alertChannel): Response
    {
        $alertChannel->delete();

        return response()->noContent();
    }

    /** Синхронно отправляет тестовое сообщение; сбой — тоже 200 с ok: false. */
    public function test(AlertChannel $alertChannel, ChannelSenderResolver $senders, AlertMessageFactory $messages): JsonResponse
    {
        $error = null;
        $skipped = false;

        try {
            $senders->for($alertChannel)->send($alertChannel, $messages->test($alertChannel->name));
        } catch (AlertSkippedException $e) {
            $skipped = true;
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = Str::limit($e->getMessage(), 1000);
        }

        return response()->json(['data' => ['ok' => $error === null, 'skipped' => $skipped, 'error' => $error]]);
    }
}
