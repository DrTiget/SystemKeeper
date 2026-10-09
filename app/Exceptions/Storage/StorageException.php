<?php

namespace App\Exceptions\Storage;

use RuntimeException;

class StorageException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
