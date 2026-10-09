<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Регистрация получателей push: браузеры (Web Push) и мобильные устройства. */
class PushController extends Controller
{
    public function vapidPublicKey(): JsonResponse
    {
        $key = config('alerts.vapid.public_key');
        abort_if(empty($key), 503, 'VAPID-ключи не настроены (php artisan alerts:vapid-keys)');

        return response()->json(['data' => ['public_key' => $key]]);
    }

    /** Тело — стандартный PushSubscription.toJSON(): {endpoint, keys: {p256dh, auth}}. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hash($data['endpoint'])],
            ['user_id' => $request->user()->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
        );

        return response()->json(['data' => ['id' => $subscription->id]], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hash($data['endpoint']))->delete();

        return response()->noContent();
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
            'token' => ['required', 'string', 'max:512'],
        ]);

        $device = DeviceToken::updateOrCreate(
            ['token_hash' => DeviceToken::hash($data['token'])],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'token' => $data['token']],
        );

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function unregisterDevice(Request $request): Response
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        DeviceToken::query()->where('user_id', $request->user()->id)->where('token_hash', DeviceToken::hash($data['token']))->delete();

        return response()->noContent();
    }
}
