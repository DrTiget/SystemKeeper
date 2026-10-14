<?php

namespace Tests\Feature;

use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_crud_with_counts(): void
    {
        $id = $this->postJson('/api/v1/projects', ['name' => 'Магазин', 'description' => 'Прод'])
            ->assertCreated()
            ->assertJsonPath('data.servers_count', 0)
            ->json('data.id');

        Server::factory()->create(['project_id' => $id]);
        DatabaseConnection::factory()->count(2)->create(['project_id' => $id]);
        Project::factory()->create(['name' => 'Блог']);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Блог')
            ->assertJsonPath('data.1.servers_count', 1)
            ->assertJsonPath('data.1.databases_count', 2);
        $this->getJson('/api/v1/projects?search=маг')->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/projects/{$id}", ['name' => 'Магазин 2'])->assertOk()->assertJsonPath('data.name', 'Магазин 2');
        $this->postJson('/api/v1/projects', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_delete_keeps_servers_and_databases(): void
    {
        $project = Project::factory()->create();
        $server = Server::factory()->create(['project_id' => $project->id]);
        $db = DatabaseConnection::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertNull($server->fresh()->project_id);
        $this->assertNull($db->fresh()->project_id);
    }

    public function test_assign_and_filter_servers_and_databases(): void
    {
        $project = Project::factory()->create(['name' => 'Магазин']);
        $server = Server::factory()->create();
        Server::factory()->create();
        $db = DatabaseConnection::factory()->create();

        $this->patchJson("/api/v1/servers/{$server->id}", ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('data.project', ['id' => $project->id, 'name' => 'Магазин']);
        $this->patchJson("/api/v1/databases/{$db->id}", ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('data.project.id', $project->id);
        $this->patchJson("/api/v1/servers/{$server->id}", ['project_id' => 999])->assertStatus(422)->assertJsonValidationErrors('project_id');

        $this->getJson("/api/v1/servers?project_id={$project->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $server->id);
        $this->getJson('/api/v1/servers?project_id=none')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/databases?project_id={$project->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/databases?project_id=none')->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/servers/{$server->id}", ['project_id' => null])->assertOk()->assertJsonPath('data.project', null);
    }

    public function test_project_backups_include_servers_and_databases(): void
    {
        $project = Project::factory()->create();
        $server = Server::factory()->create(['project_id' => $project->id]);
        $db = DatabaseConnection::factory()->create(['project_id' => $project->id]);
        $other = Server::factory()->create();
        foreach ([$server, $db, $other] as $source) {
            $source->backups()->create(['trigger' => 'manual', 'status' => 'success']);
        }

        $this->getJson("/api/v1/projects/{$project->id}/backups")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', ['type' => 'database', 'id' => $db->id, 'name' => $db->name])
            ->assertJsonPath('data.1.source.id', $server->id);
    }
}
