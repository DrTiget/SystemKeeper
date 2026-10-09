<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_require_auth_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/api/docs.json')->assertUnauthorized();
        $this->get('/api/docs')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->get('/api/docs')->assertOk();
    }

    public function test_docs_open_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->getJson('/api/docs.json')->assertOk();
    }

    public function test_spec_covers_all_api_routes_and_key_schemas(): void
    {
        $this->app['env'] = 'local';
        $spec = $this->getJson('/api/docs.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(['http' => ['type' => 'http', 'scheme' => 'bearer']], $spec['components']['securitySchemes']);

        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), strlen('api/v1')));
            // PUT и PATCH в OpenAPI описаны одной операцией; HEAD не документируется
            $methods = array_diff($route->methods(), ['HEAD', 'PATCH']);
            $this->assertNotEmpty(array_intersect(array_map(fn ($m) => "{$m} {$uri}", $methods), $documented), 'Не описан '.$route->uri());
        }

        $schemas = array_keys($spec['components']['schemas']);
        foreach (['StorageTargetResource', 'ServerResource', 'BackupResource', 'MonitorResource', 'StoreStorageTargetRequest'] as $name) {
            $this->assertContains($name, $schemas);
        }
        $this->assertArrayHasKey('secret_key', $spec['components']['schemas']['StoreStorageTargetRequest']['properties']);
    }
}
