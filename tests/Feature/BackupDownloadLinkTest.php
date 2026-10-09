<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupDownloadLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = '0123456789abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        Carbon::setTestNow('2026-10-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function backup(): Backup
    {
        Storage::disk('backups')->put('1/1/a.tar.zst', self::CONTENT);

        return Backup::factory()->success('1/1/a.tar.zst', self::CONTENT)->create();
    }

    /** Путь с query из ссылки, которую выдал API. */
    private function issueLink(Backup $backup): string
    {
        Sanctum::actingAs(User::factory()->create());
        $url = $this->postJson("/api/v1/backups/{$backup->id}/download-link")->assertOk()->json('data.url');
        $this->app['auth']->forgetGuards();

        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_link_endpoint_requires_authentication(): void
    {
        $backup = $this->backup();

        $this->postJson("/api/v1/backups/{$backup->id}/download-link")->assertUnauthorized();
    }

    public function test_issues_signed_link_with_five_minute_expiry(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $backup = $this->backup();

        $response = $this->postJson("/api/v1/backups/{$backup->id}/download-link")
            ->assertOk()
            ->assertJsonPath('data.expires_at', now()->addMinutes(5)->toIso8601String());

        $url = $response->json('data.url');
        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString("/api/v1/backups/{$backup->id}/file?", $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame((string) now()->addMinutes(5)->timestamp, $query['expires']);
        $this->assertNotEmpty($query['signature']);
    }

    public function test_link_downloads_file_without_bearer_with_headers(): void
    {
        $backup = $this->backup();
        $path = $this->issueLink($backup);

        $response = $this->get($path);

        $response->assertOk()->assertDownload('a.tar.zst');
        $this->assertSame(self::CONTENT, $response->streamedContent());
        $this->assertSame((string) strlen(self::CONTENT), $response->headers->get('Content-Length'));
        $this->assertSame(hash('sha256', self::CONTENT), $response->headers->get('X-Checksum-Sha256'));
        $this->assertSame('application/zstd', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_link_supports_range_requests(): void
    {
        $path = $this->issueLink($this->backup());

        $response = $this->get($path, ['Range' => 'bytes=5-9']);

        $response->assertStatus(206);
        $this->assertSame('56789', $response->streamedContent());
        $this->assertSame('bytes 5-9/'.strlen(self::CONTENT), $response->headers->get('Content-Range'));
        $this->assertSame('5', $response->headers->get('Content-Length'));

        $tail = $this->get($path, ['Range' => 'bytes=15-']);
        $tail->assertStatus(206);
        $this->assertSame('fghij', $tail->streamedContent());
    }

    public function test_link_works_until_expiry_and_gives_403_after(): void
    {
        $path = $this->issueLink($this->backup());

        Carbon::setTestNow(now()->addMinutes(4)->addSeconds(59));
        $this->get($path)->assertOk();

        Carbon::setTestNow(now()->addSeconds(2));
        $this->get($path)->assertForbidden();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $path = $this->issueLink($this->backup());

        $this->get(preg_replace('/signature=(.)/', 'signature=x', $path))->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $path))->assertForbidden();
        $this->get(preg_replace('/&signature=[0-9a-f]+/', '', $path))->assertForbidden();
    }

    public function test_tampered_expiry_or_backup_id_is_rejected(): void
    {
        $backup = $this->backup();
        $other = $this->backup();
        $path = $this->issueLink($backup);

        $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $path))->assertForbidden();
        $this->get(str_replace("/backups/{$backup->id}/file", "/backups/{$other->id}/file", $path))->assertForbidden();
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $backup = $this->backup();

        $this->get("/api/v1/backups/{$backup->id}/file")->assertForbidden();
    }

    public function test_signature_does_not_depend_on_host(): void
    {
        $path = $this->issueLink($this->backup());

        $this->get('http://another-host.example'.$path)->assertOk();
    }

    public function test_no_link_for_unavailable_backup(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $failed = Backup::factory()->failed()->create();
        $missing = Backup::factory()->success('1/1/gone.tar.zst')->create();

        $this->postJson("/api/v1/backups/{$failed->id}/download-link")->assertNotFound();
        $this->postJson("/api/v1/backups/{$missing->id}/download-link")->assertNotFound();
        $this->postJson('/api/v1/backups/9999/download-link')->assertNotFound();
    }

    public function test_valid_link_returns_404_after_file_is_gone(): void
    {
        $backup = $this->backup();
        $path = $this->issueLink($backup);

        Storage::disk('backups')->delete('1/1/a.tar.zst');

        $this->getJson($path)->assertNotFound();
    }

    public function test_ttl_is_configurable(): void
    {
        config(['backup.download_link_minutes' => 1]);
        $path = $this->issueLink($this->backup());

        Carbon::setTestNow(now()->addSeconds(61));
        $this->get($path)->assertForbidden();
    }

    public function test_bearer_download_still_works_with_range(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $backup = $this->backup();

        $this->get("/api/v1/backups/{$backup->id}/download")->assertOk()->assertDownload('a.tar.zst');
        $this->get("/api/v1/backups/{$backup->id}/download", ['Range' => 'bytes=0-3'])->assertStatus(206);
    }
}
