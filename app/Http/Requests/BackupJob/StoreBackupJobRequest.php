<?php

namespace App\Http\Requests\BackupJob;

class StoreBackupJobRequest extends BackupJobRequest
{
    protected function jobType(): ?string
    {
        return $this->input('type');
    }
}
