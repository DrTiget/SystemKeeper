<?php

namespace App\Services\Alerts;

/**
 * Состояние объекта по правилу в данный момент: нарушено ли условие (null — не знаем: нет свежих данных) и сообщение.
 */
final class Observation
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $projectId,
        public readonly ?bool $breached,
        public readonly string $message = '',
    ) {}

    public function key(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
