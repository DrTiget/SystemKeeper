<?php

namespace App\Http\Requests\BackupJob;

use App\Models\BackupJob;

class UpdateBackupJobRequest extends BackupJobRequest
{
    protected function existing(): ?BackupJob
    {
        return $this->route('backup_job');
    }

    protected function jobType(): ?string
    {
        return $this->existing()?->type;
    }
}
