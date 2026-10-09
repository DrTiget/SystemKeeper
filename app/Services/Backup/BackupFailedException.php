<?php

namespace App\Services\Backup;

use RuntimeException;

class BackupFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $log = '')
    {
        parent::__construct($message);
    }
}
