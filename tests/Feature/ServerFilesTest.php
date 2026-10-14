<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\RemoteFiles;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ServerFilesTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->server = Server::factory()->create();
    }

    private function fake(array $result = [], ?\Throwable $throw = null): FakeSshConnector
    {
        $fake = new FakeSshConnector([...['exit_code' => 0, 'stdout' => '', 'stderr' => ''], ...$result], $throw);
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    public function test_normalize(): void
    {
        $this->assertSame('/', RemoteFiles::normalize('/'));
        $this->assertSame('/var/www', RemoteFiles::normalize('//var/./log/../www/'));
        $this->assertSame('/', RemoteFiles::normalize('/../..'));
    }

    public function test_lists_directory_dirs_first(): void
    {
        $stdout = implode("\0", [
            "f\tf\t1200\tnginx.conf",
            "d\td\t4096\tsites-enabled",
            "l\td\t7\tconf.d",
            "l\tf\t9\tlink.txt",
            "f\tf\t5\tname with\ttab",
            "s\ts\t0\tsock",
            "d\td\t4096\tAlpha",
        ])."\0";
        $fake = $this->fake(['stdout' => $stdout]);

        $response = $this->getJson("/api/v1/servers/{$this->server->id}/files?path=/etc/nginx/../nginx/")
            ->assertOk()
            ->assertJsonPath('data.path', '/etc/nginx')
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.entries.*.name', ['Alpha', 'conf.d', 'sites-enabled', 'link.txt', 'name with	tab', 'nginx.conf', 'sock']);

        $this->assertSame(['name' => 'conf.d', 'path' => '/etc/nginx/conf.d', 'type' => 'dir', 'link' => true, 'size' => null], $response->json('data.entries.1'));
        $this->assertSame(1200, $response->json('data.entries.5.size'));
        $this->assertSame('other', $response->json('data.entries.6.type'));
        $this->assertStringContainsString("cd '/etc/nginx' || exit 1", $fake->calls[0]['command']);
    }

    public function test_path_is_shell_escaped(): void
    {
        $fake = $this->fake();

        $this->getJson("/api/v1/servers/{$this->server->id}/files?path=".urlencode("/tmp/x'; rm -rf / #"))->assertOk();

        $this->assertStringContainsString("cd '/tmp/x'\\''; rm -rf / #' || exit 1", $fake->calls[0]['command']);
    }

    public function test_truncates_long_listing(): void
    {
        config(['backup.browse_limit' => 2]);
        $this->fake(['stdout' => "f\tf\t1\ta\0f\tf\t1\tb\0f\tf\t1\tc\0"]);

        $this->getJson("/api/v1/servers/{$this->server->id}/files")
            ->assertJsonPath('data.path', '/')
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.truncated', true);
    }

    public function test_output_cut_by_size_drops_partial_entry(): void
    {
        // старый head без -z (CentOS 7) обрезает вывод по байтам
        $fake = $this->fake(['stdout' => "f\tf\t1\ta\0d\td\t0\tbin\0f\tf\t1\tpart"]);

        $this->getJson("/api/v1/servers/{$this->server->id}/files")
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.truncated', true);
        $this->assertStringContainsString('else head -c 8388608; fi', $fake->calls[0]['command']);
    }

    public function test_errors(): void
    {
        $url = "/api/v1/servers/{$this->server->id}/files";

        $this->getJson("{$url}?path=relative")->assertUnprocessable()->assertJsonPath('message', 'Нужен абсолютный путь.');

        $this->fake(['exit_code' => 3, 'stderr' => 'not a directory']);
        $this->getJson("{$url}?path=/nope")->assertUnprocessable()->assertJsonPath('message', 'Каталог /nope не найден.');

        $this->fake(['exit_code' => 1, 'stderr' => "find: '.': Permission denied"]);
        $this->getJson("{$url}?path=/root")->assertUnprocessable()->assertJsonPath('message', 'Нет доступа к /root.');

        $this->fake(throw: new SshConnectionException('Не удалось подключиться к h:22: timeout'));
        $this->getJson($url)->assertStatus(502)->assertJsonPath('message', 'Не удалось подключиться к h:22: timeout');
    }

    public function test_pre_command_templates(): void
    {
        $this->getJson('/api/v1/backup/pre-command-templates')
            ->assertOk()
            ->assertJsonStructure(['data' => [['key', 'group', 'title', 'description', 'command']]]);
    }
}
