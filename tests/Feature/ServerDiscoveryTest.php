<?php

namespace Tests\Feature;

use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ServerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_parses_paths_sizes_excludes_and_databases(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();
        DatabaseConnection::factory()->create(['server_id' => $server->id, 'engine' => 'mysql', 'via' => 'ssh', 'docker_container' => 'shop-db']);

        $fake = new FakeSshConnector;
        $fake->results = [
            'sh -s' => ['exit_code' => 0, 'stderr' => '', 'stdout' => implode("\n", [
                "I\thostname\tweb1",
                "I\tdocker\t27.1",
                "P\t/etc\tsystem\tНастройки",
                "P\t/opt\tapps\tПриложения",
                "P\t/opt/shop/\tdocker\tКаталог compose",
                "P\t/opt/shop\tweb\tКорень сайта в nginx",
                "P\t/var/lib/docker/volumes\tdocker_volumes\tТома",
                "D\tmysql\tdocker:shop-db\tmariadb:11",
                "D\tpostgres\tlocal\tpostgres 16",
                "D\tredis\tlocal\tRedis 7",
                "X\tnode_modules\tЗависимости",
                'мусор',
            ])],
            'du -sxk' => ['exit_code' => 0, 'stderr' => '', 'stdout' => "12\t/etc\n2048\t/opt\n\t/var/lib/docker/volumes\n"],
        ];
        $this->app->instance(SshConnectorInterface::class, $fake);

        $data = $this->postJson("/api/v1/servers/{$server->id}/discover")->assertOk()->json('data');

        $this->assertSame(['hostname' => 'web1', 'docker' => '27.1'], $data['info']);
        $paths = collect($data['paths'])->keyBy('path');
        $this->assertSame(['/etc', '/opt', '/opt/shop', '/var/lib/docker/volumes'], $paths->keys()->all());
        $this->assertSame(12 * 1024, $paths['/etc']['size']);
        $this->assertNull($paths['/var/lib/docker/volumes']['size']);
        $this->assertSame('/opt', $paths['/opt/shop']['inside']);
        $this->assertSame(['Каталог compose', 'Корень сайта в nginx'], $paths['/opt/shop']['reasons']);
        $this->assertTrue($paths['/opt']['recommended']);
        $this->assertFalse($paths['/var/lib/docker/volumes']['recommended']);
        $this->assertSame([['pattern' => 'node_modules', 'reason' => 'Зависимости']], $data['excludes']);

        $dbs = collect($data['databases'])->keyBy('engine');
        $this->assertSame('shop-db', $dbs['mysql']['container']);
        $this->assertTrue($dbs['mysql']['added']);
        $this->assertFalse($dbs['postgres']['added']);
        $this->assertSame('local', $dbs['postgres']['where']);
        $this->assertFalse($dbs['redis']['supported']);

        // скрипт уходит через stdin, du — только по верхним каталогам
        $this->assertStringContainsString('docker ps', $fake->calls[0]['stdin']);
        $this->assertStringNotContainsString("'/opt/shop'", $fake->calls[1]['command']);
    }

    public function test_script_failure_is_reported(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();
        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(['exit_code' => 127, 'stdout' => '', 'stderr' => 'sh: not found']));

        $this->postJson("/api/v1/servers/{$server->id}/discover")->assertStatus(422)->assertJsonPath('message', 'Не удалось осмотреть сервер: sh: not found');
    }
}
