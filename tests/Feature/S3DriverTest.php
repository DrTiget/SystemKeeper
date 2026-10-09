<?php

namespace Tests\Feature;

use App\Exceptions\Storage\StorageException;
use App\Models\StorageTarget;
use App\Services\Storage\Drivers\S3Driver;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class S3DriverTest extends TestCase
{
    private const MB = 1048576;

    private string $file;

    /** @var list<string> имена выполненных операций SDK */
    private array $ops = [];

    /** @var list<array<string, mixed>> параметры выполненных команд */
    private array $params = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'sk-s3-');
        config(['storage_targets.s3.part_size' => 5 * self::MB, 'storage_targets.s3.multipart_threshold' => 8 * self::MB]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function driver(array $queue, array $config = []): S3Driver
    {
        $mock = new MockHandler;
        foreach ($queue as $item) {
            $mock->append($item);
        }
        $client = new S3Client([
            'version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
        $client->getHandlerList()->appendInit(function (callable $next) {
            return function (CommandInterface $cmd, ?RequestInterface $req = null) use ($next) {
                $this->ops[] = $cmd->getName();
                $this->params[] = $cmd->toArray();

                return $next($cmd, $req);
            };
        });

        $target = StorageTarget::factory()->s3($config)->make();

        return new S3Driver($target, $client);
    }

    public function test_small_file_is_put_and_etag_verified(): void
    {
        file_put_contents($this->file, str_repeat('x', 1000));
        $md5 = md5_file($this->file);
        $driver = $this->driver([new Result, new Result(['ContentLength' => 1000, 'ETag' => "\"{$md5}\""])], ['storage_class' => 'STANDARD_IA']);

        $result = $driver->upload($this->file, '/backups/srv/a.bin');

        $this->assertSame(['md5' => $md5, 'sha256' => null], $result);
        $this->assertSame(['PutObject', 'HeadObject'], $this->ops);
        $this->assertSame('backups/srv/a.bin', $this->params[0]['Key']);
        $this->assertSame('sk-bucket', $this->params[0]['Bucket']);
        $this->assertSame('STANDARD_IA', $this->params[0]['StorageClass']);
    }

    public function test_etag_mismatch_fails_and_removes_object(): void
    {
        file_put_contents($this->file, 'data');
        $driver = $this->driver([new Result, new Result(['ContentLength' => 4, 'ETag' => '"'.str_repeat('0', 32).'"']), new Result]);

        try {
            $driver->upload($this->file, '/backups/a.bin');
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertStringContainsString('(ETag) не совпала', $e->getMessage());
        }
        $this->assertSame(['PutObject', 'HeadObject', 'DeleteObject'], $this->ops);
    }

    public function test_size_mismatch_fails(): void
    {
        file_put_contents($this->file, 'data');
        $driver = $this->driver([new Result, new Result(['ContentLength' => 3, 'ETag' => '"'.md5('dat').'"']), new Result]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Размер объекта');
        $driver->upload($this->file, '/backups/a.bin');
    }

    public function test_non_standard_etag_falls_back_to_size_check(): void
    {
        file_put_contents($this->file, 'data');
        $driver = $this->driver([new Result, new Result(['ContentLength' => 4, 'ETag' => '"kms-encrypted-etag"'])]);

        $this->assertSame(md5('data'), $driver->upload($this->file, '/backups/a.bin')['md5']);
    }

    public function test_large_file_uses_multipart_with_composite_etag(): void
    {
        // 11 МиБ при части 5 МиБ: 5 + 5 + 1
        $content = random_bytes(11 * self::MB);
        file_put_contents($this->file, $content);
        $composite = md5(md5(substr($content, 0, 5 * self::MB), true).md5(substr($content, 5 * self::MB, 5 * self::MB), true).md5(substr($content, 10 * self::MB), true)).'-3';

        $driver = $this->driver([
            new Result(['UploadId' => 'up1']),
            new Result(['ETag' => '"p1"']), new Result(['ETag' => '"p2"']), new Result(['ETag' => '"p3"']),
            new Result(['Location' => 'x']),
            new Result(['ContentLength' => 11 * self::MB, 'ETag' => "\"{$composite}\""]),
        ], ['storage_class' => 'COLD']);

        $result = $driver->upload($this->file, '/backups/big.bin');

        $this->assertSame(md5($content), $result['md5']);
        $this->assertSame('CreateMultipartUpload', $this->ops[0]);
        $this->assertSame('COLD', $this->params[0]['StorageClass']);
        $this->assertSame(3, count(array_keys($this->ops, 'UploadPart', true)));
        $this->assertSame('CompleteMultipartUpload', $this->ops[4]);
        $this->assertArrayNotHasKey('StorageClass', $this->params[1]);
    }

    public function test_failed_multipart_is_aborted(): void
    {
        file_put_contents($this->file, random_bytes(9 * self::MB));
        $error = fn () => new AwsException('boom', new Command('UploadPart'), ['code' => 'InternalError']);

        $driver = $this->driver([new Result(['UploadId' => 'up1']), $error(), $error(), new Result]);

        try {
            $driver->upload($this->file, '/backups/big.bin');
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Ошибка S3', $e->getMessage());
        }
        $this->assertContains('AbortMultipartUpload', $this->ops);
    }

    public function test_aws_errors_are_translated_and_do_not_leak_secrets(): void
    {
        file_put_contents($this->file, 'data');
        $denied = new AwsException('msg', new Command('PutObject'), ['code' => 'AccessDenied', 'response' => new Response(403)]);
        $driver = $this->driver([$denied]);

        try {
            $driver->upload($this->file, '/backups/a.bin');
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Доступ запрещён', $e->getMessage());
            $this->assertStringNotContainsString('sk-secret-key', $e->getMessage());
        }
    }

    public function test_delete_removes_object_and_guards_prefix(): void
    {
        $driver = $this->driver([new Result]);
        $driver->delete('/backups/srv/a.bin');
        $this->assertSame(['DeleteObject'], $this->ops);
        $this->assertSame('backups/srv/a.bin', $this->params[0]['Key']);

        foreach (['/other/a.bin', '/backups/../other/a.bin'] as $bad) {
            try {
                $driver->delete($bad);
                $this->fail('Ожидалось исключение для '.$bad);
            } catch (StorageException $e) {
                $this->assertStringContainsString('вне префикса', $e->getMessage());
            }
        }
        $this->assertCount(1, $this->ops);
    }

    public function test_check_heads_bucket_and_writes_probe_object(): void
    {
        $driver = $this->driver([new Result, new Result, new Result]);

        $this->assertSame(['total_space' => null, 'used_space' => null, 'free_space' => null], $driver->check());
        $this->assertSame(['HeadBucket', 'PutObject', 'DeleteObject'], $this->ops);
        $this->assertStringStartsWith('backups/.systemkeeper-check-', $this->params[1]['Key']);
    }

    public function test_check_reports_missing_bucket(): void
    {
        $driver = $this->driver([new AwsException('x', new Command('HeadBucket'), ['code' => 'NoSuchBucket', 'response' => new Response(404)])]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Бакет не найден');
        $driver->check();
    }
}
