<?php

namespace App\Exceptions\Ssh;

use RuntimeException;

class HostFingerprintChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Отпечаток хоста изменился');
    }
}
