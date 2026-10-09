<?php

namespace App\Console\Commands;

use App\Models\AlertDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:prune')]
#[Description('Удаляет старые записи журнала отправок алертов')]
class PruneAlertDeliveries extends Command
{
    public function handle(): int
    {
        $deleted = AlertDelivery::query()->where('created_at', '<', now()->subDays((int) config('alerts.delivery_retention_days')))->delete();
        $this->info("Удалено записей журнала: {$deleted}");

        return self::SUCCESS;
    }
}
