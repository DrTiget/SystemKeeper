<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SshKey\StoreSshKeyRequest;
use App\Http\Requests\SshKey\UpdateSshKeyRequest;
use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use App\Services\Ssh\SshKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SshKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return SshKeyResource::collection(SshKey::query()->withCount('servers')->orderBy('name')->paginate($perPage));
    }

    public function store(StoreSshKeyRequest $request, SshKeyService $keys): JsonResponse
    {
        try {
            $material = $request->filled('private_key')
                ? $keys->import($request->input('private_key'), $request->input('passphrase'), $request->input('name'))
                : $keys->generate($request->input('type', 'ed25519'), $request->input('name'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['private_key' => [$e->getMessage()]]);
        }

        $key = SshKey::create([
            'name' => $request->input('name'),
            'private_key' => $material->privateKey,
            'passphrase' => $material->passphrase,
            'public_key' => $material->publicKey,
            'fingerprint' => $material->fingerprint,
            'type' => $material->type,
        ]);

        return (new SshKeyResource($key))->response()->setStatusCode(201);
    }

    public function show(SshKey $sshKey): SshKeyResource
    {
        return new SshKeyResource($sshKey->loadCount('servers'));
    }

    public function update(UpdateSshKeyRequest $request, SshKey $sshKey): SshKeyResource
    {
        $sshKey->update(['name' => $request->input('name')]);

        return new SshKeyResource($sshKey->loadCount('servers'));
    }

    public function destroy(SshKey $sshKey): Response|JsonResponse
    {
        if ($sshKey->servers()->exists()) {
            return response()->json(['message' => 'Ключ используется серверами, удалить его нельзя.'], 409);
        }

        $sshKey->delete();

        return response()->noContent();
    }

    public function publicKey(SshKey $sshKey): JsonResponse
    {
        return response()->json(['data' => ['public_key' => $sshKey->public_key, 'fingerprint' => $sshKey->fingerprint]]);
    }
}
