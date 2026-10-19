<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;

/**
 * Итог одной проверки: достигнут ли объект (`up`/`down` — повторные попытки решает MonitorStatus), время ответа, код HTTP,
 * пояснение и (для https) сведения о сертификате.
 */
final class CheckResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $responseMs = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $message = null,
        public readonly ?Carbon $sslValidTo = null,
        public readonly ?string $sslIssuer = null,
    ) {}

    public static function up(?int $responseMs, ?string $message = null, ?int $statusCode = null): self
    {
        return new self(true, $responseMs, $statusCode, $message);
    }

    public static function down(string $message, ?int $responseMs = null, ?int $statusCode = null): self
    {
        return new self(false, $responseMs, $statusCode, $message);
    }

    public function withCertificate(?Carbon $validTo, ?string $issuer): self
    {
        return new self($this->ok, $this->responseMs, $this->statusCode, $this->message, $validTo, $issuer);
    }
}
