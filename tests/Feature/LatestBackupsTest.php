<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LatestBackupsTest extends TestCase
{
    use RefreshDatabase;

    private function backup(Model $source, string $status, string $finished): Backup
    {
        $backup = new Backup(['trigger' => Backup::TRIGGER_SCHEDULE, 'status' => $status, 'finished_at' => $finished]);
        $backup->source()->associate($source)->save();

        return $backup;
    }

    public function test_latest_backup_per_source_with_tree(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $group = ProjectGroup::create(['name' => 'X-Amo']);
        $project = Project::factory()->create(['group_id' => $group->id]);
        $server = Server::factory()->create(['project_id' => $project->id]);
        $db = DatabaseConnection::factory()->create(['server_id' => $server->id, 'project_id' => $project->id]);
        $empty = Server::factory()->create();

        $this->backup($server, Backup::SUCCESS, '2026-10-14 03:10:00');
        $this->backup($server, Backup::SUCCESS, '2026-10-15 03:10:00');
        $last = $this->backup($server, Backup::FAILED, '2026-10-16 03:10:00');
        $this->backup($db, Backup::SUCCESS, '2026-10-15 04:00:00');

        $data = $this->getJson('/api/v1/backups/latest')->assertOk()->json('data');

        $this->assertSame([['id' => $group->id, 'name' => 'X-Amo']], $data['groups']);
        $this->assertSame($group->id, $data['projects'][0]['group_id']);
        $s = collect($data['servers'])->firstWhere('id', $server->id);
        $this->assertSame($last->id, $s['latest']['id']);
        $this->assertSame('failed', $s['latest']['status']);
        $this->assertStringStartsWith('2026-10-15T03:10:00', $s['last_success_at']);
        $this->assertNull(collect($data['servers'])->firstWhere('id', $empty->id)['latest']);
        $d = collect($data['databases'])->firstWhere('id', $db->id);
        $this->assertSame($server->id, $d['server_id']);
        $this->assertSame('success', $d['latest']['status']);
    }
}
