<?php

namespace App\Services\Monitoring;

final readonly class CheckResult
{
    public function __construct(
        public bool $up,
        public ?int $responseTimeMs = null,
        public ?int $statusCode = null,
        public ?string $error = null,
        public ?int $sslDaysLeft = null,
    ) {}

    public static function up(?int $responseTimeMs = null, ?int $statusCode = null): self
    {
        return new self(true, $responseTimeMs, $statusCode);
    }

    public static function down(string $error, ?int $responseTimeMs = null, ?int $statusCode = null, ?int $sslDaysLeft = null): self
    {
        return new self(false, $responseTimeMs, $statusCode, $error, $sslDaysLeft);
    }
}
