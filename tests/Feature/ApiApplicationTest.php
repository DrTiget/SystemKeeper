<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiApplicationTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $scopes */
    private function makeApp(array $scopes, bool $active = true): array
    {
        $app = ApiApplication::create(['name' => 'CI', 'scopes' => $scopes, 'is_active' => $active]);

        return [$app, $app->issueToken()];
    }

    /** Запрос с токеном приложения (кеш guard сбрасывается — как в отдельном HTTP-запросе). */
    private function asApp(string $method, string $uri, ?string $token, array $data = [], string $header = 'bearer'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = $token === null ? [] : ($header === 'bearer' ? ['Authorization' => "Bearer {$token}"] : ['X-Api-Key' => $token]);

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    public function test_only_admins_manage_applications(): void
    {
        $this->getJson('/api/v1/applications')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/applications')->assertForbidden();
        $this->postJson('/api/v1/applications', ['name' => 'x', 'scopes' => []])->assertForbidden();
    }

    public function test_admin_creates_application_and_token_is_shown_once_and_hashed(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Админ']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/applications/scopes')->assertOk()->assertJsonFragment(['key' => 'servers', 'label' => 'Серверы']);
        $this->postJson('/api/v1/applications', ['name' => 'x', 'scopes' => ['servers:delete']])->assertJsonValidationErrors('scopes.0');

        $response = $this->postJson('/api/v1/applications', ['name' => 'Grafana', 'description' => 'Дашборды', 'scopes' => ['servers:read', 'alerts:write']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grafana')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.created_by.name', 'Админ');
        $token = $response->json('data.token');
        $id = $response->json('data.id');

        $this->assertStringStartsWith('ska_', $token);
        $app = ApiApplication::find($id);
        $this->assertSame(hash('sha256', $token), $app->token_hash);
        $this->assertSame(substr($token, 0, 10), $response->json('data.token_prefix'));
        $this->assertDatabaseMissing('api_applications', ['token_hash' => $token]);

        $this->getJson("/api/v1/applications/{$id}")->assertOk()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.token_hash');
        $this->getJson('/api/v1/applications')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token');

        $this->patchJson("/api/v1/applications/{$id}", ['is_active' => false, 'scopes' => ['*:read']])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.scopes', ['*:read']);

        $this->deleteJson("/api/v1/applications/{$id}")->assertNoContent();
        $this->assertDatabaseCount('api_applications', 0);
    }

    public function test_application_authenticates_by_bearer_and_x_api_key(): void
    {
        [$app, $token] = $this->makeApp(['servers:read']);
        Server::factory()->create(['name' => 'web-1']);

        $this->asApp('GET', '/api/v1/servers', $token)->assertOk()->assertJsonPath('data.0.name', 'web-1');
        $this->asApp('GET', '/api/v1/servers', $token, header: 'x-api-key')->assertOk();
        $this->asApp('GET', '/api/v1/servers', 'ska_wrong')->assertUnauthorized();
        $this->asApp('GET', '/api/v1/servers', null)->assertUnauthorized();

        $app->refresh();
        $this->assertNotNull($app->last_used_at);
        $this->assertSame('127.0.0.1', $app->last_used_ip);
    }

    public function test_scopes_limit_sections_and_write_access(): void
    {
        [, $read] = $this->makeApp(['servers:read']);
        $server = Server::factory()->create();

        $this->asApp('GET', "/api/v1/servers/{$server->id}", $read)->assertOk();
        $this->asApp('PATCH', "/api/v1/servers/{$server->id}", $read, ['name' => 'new'])
            ->assertForbidden()->assertJsonPath('message', 'У приложения нет права servers:write.');
        $this->asApp('GET', '/api/v1/projects', $read)->assertForbidden()->assertJsonPath('message', 'У приложения нет права projects:read.');
        $this->asApp('GET', '/api/v1/monitors', $read)->assertForbidden();

        [, $write] = $this->makeApp(['projects:write']);
        $this->asApp('GET', '/api/v1/projects', $write)->assertOk();
        $this->asApp('POST', '/api/v1/projects', $write, ['name' => 'Сайт'])->assertCreated();
        $this->asApp('GET', '/api/v1/servers', $write)->assertForbidden();

        [, $all] = $this->makeApp(['*:read']);
        $this->asApp('GET', '/api/v1/monitors', $all)->assertOk();
        $this->asApp('GET', '/api/v1/alerts', $all)->assertOk();
        $this->asApp('DELETE', "/api/v1/servers/{$server->id}", $all)->assertForbidden();
    }

    public function test_inactive_revoked_and_regenerated_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        [$app, $token] = $this->makeApp(['*:write']);

        $this->asApp('GET', '/api/v1/projects', $token)->assertOk();

        $app->update(['is_active' => false]);
        $this->asApp('GET', '/api/v1/projects', $token)->assertUnauthorized();
        $app->update(['is_active' => true]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $new = $this->postJson("/api/v1/applications/{$app->id}/token")->assertOk()->json('data.token');
        $this->assertNotSame($token, $new);

        $this->asApp('GET', '/api/v1/projects', $token)->assertUnauthorized();
        $this->asApp('GET', '/api/v1/projects', $new)->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/applications/{$app->id}/token")->assertOk()->assertJsonPath('data.has_token', false);
        $this->asApp('GET', '/api/v1/projects', $new)->assertUnauthorized();

        $this->assertTrue(AuditLog::where('event', 'api_application.token_issued')->exists());
        $this->assertTrue(AuditLog::where('event', 'api_application.token_revoked')->exists());
    }

    public function test_user_only_endpoints_are_closed_for_applications(): void
    {
        [, $token] = $this->makeApp(['*:write']);

        $this->asApp('GET', '/api/v1/auth/me', $token)->assertUnauthorized();
        $this->asApp('GET', '/api/v1/profile', $token)->assertUnauthorized();
        $this->asApp('GET', '/api/v1/users', $token)->assertUnauthorized();
        $this->asApp('GET', '/api/v1/applications', $token)->assertUnauthorized();
        $this->asApp('GET', '/api/v1/settings/notifications', $token)->assertUnauthorized();
        $this->asApp('POST', '/api/v1/system/update', $token)->assertForbidden();
        $this->asApp('GET', '/api/v1/system/version', $token)->assertOk();
    }

    public function test_application_changes_are_attributed_to_application(): void
    {
        [$app, $token] = $this->makeApp(['projects:write']);

        $id = $this->asApp('POST', '/api/v1/projects', $token, ['name' => 'Магазин'])->assertCreated()->json('data.id');
        $this->asApp('PATCH', "/api/v1/projects/{$id}", $token, ['name' => 'Магазин 2'])->assertOk();

        $log = AuditLog::where('event', 'project.updated')->sole();
        $this->assertSame(AuditLog::ACTOR_APPLICATION, $log->actor_type);
        $this->assertSame($app->id, $log->actor_id);
        $this->assertSame('CI', $log->actor_name);
        $this->assertEquals(['old' => ['name' => 'Магазин'], 'new' => ['name' => 'Магазин 2']], $log->changes);
        $this->assertSame(Project::find($id)->id, $log->subject_id);
    }

    public function test_application_may_read_docs(): void
    {
        $this->app['env'] = 'production';
        [, $token] = $this->makeApp(['servers:read']);

        $this->asApp('GET', '/api/docs.json', $token)->assertOk();
    }
}
