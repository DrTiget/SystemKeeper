<?php

namespace App\Services\Alerts;

/** Готовое к отправке сообщение и объект, к которому оно относится (для фильтров правил и антиспама). */
final readonly class AlertMessage
{
    public function __construct(
        public string $event,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $objectKey = null,
        public ?int $monitorId = null,
        public ?int $backupJobId = null,
        public ?int $serverId = null,
        public ?int $storageTargetId = null,
    ) {}

    public function targetId(string $type): ?int
    {
        return match ($type) {
            'monitor' => $this->monitorId,
            'backup_job' => $this->backupJobId,
            'server' => $this->serverId,
            'storage_target' => $this->storageTargetId,
            default => null,
        };
    }

    /** Текст для каналов без заголовка (Telegram, email-тело). */
    public function text(): string
    {
        return $this->title."\n\n".$this->body.($this->url ? "\n\n".$this->url : '');
    }
}
