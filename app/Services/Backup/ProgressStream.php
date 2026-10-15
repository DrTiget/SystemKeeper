<?php

namespace App\Services\Backup;

use php_user_filter;

/**
 * Считает байты, прочитанные из потока, и раз в несколько секунд сообщает о них (прогресс копирования).
 * Подключение: ProgressStream::watch($stream, fn (int $bytes) => …).
 */
class ProgressStream extends php_user_filter
{
    private const NAME = 'systemkeeper.progress';

    private int $bytes = 0;

    private float $reportedAt = 0;

    /** @param resource $stream */
    public static function watch($stream, callable $onProgress, float $every = 3.0): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, ['callback' => $onProgress, 'every' => $every]);
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->bytes += $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        $now = microtime(true);
        if ($closing || $now - $this->reportedAt >= $this->params['every']) {
            $this->reportedAt = $now;
            ($this->params['callback'])($this->bytes);
        }

        return PSFS_PASS_ON;
    }
}
