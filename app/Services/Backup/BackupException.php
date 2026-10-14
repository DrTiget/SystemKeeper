<?php

namespace App\Services\Backup;

use RuntimeException;

/** Бекап не удался; сообщение показывается пользователю. */
class BackupException extends RuntimeException {}
