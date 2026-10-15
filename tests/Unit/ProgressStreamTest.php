<?php

namespace Tests\Unit;

use App\Services\Backup\ProgressStream;
use PHPUnit\Framework\TestCase;

class ProgressStreamTest extends TestCase
{
    public function test_reports_read_bytes_and_keeps_data(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, str_repeat('a', 100_000));
        rewind($stream);

        $reports = [];
        ProgressStream::watch($stream, function (int $bytes) use (&$reports) {
            $reports[] = $bytes;
        }, every: 0);

        $data = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(100_000, strlen($data));
        $this->assertSame(100_000, end($reports));
        $this->assertGreaterThan(1, count($reports));
    }
}
