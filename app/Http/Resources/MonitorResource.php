<?php

namespace App\Http\Resources;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Monitor
 */
class MonitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stats = $this->stats ?? ['uptime' => ['h24' => null, 'd7' => null, 'd30' => null], 'avg_response_ms' => null, 'recent' => []];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'project_id' => $this->project_id,
            'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'url' => $this->url,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'method' => $this->method,
            'accepted_statuses' => $this->accepted_statuses,
            'keyword' => $this->keyword,
            'keyword_invert' => $this->keyword_invert,
            'headers' => $this->headers ?? [],
            'body' => $this->body,
            'follow_redirects' => $this->follow_redirects,
            'ignore_tls' => $this->ignore_tls,
            'ssl_expiry_days' => $this->ssl_expiry_days,
            'dns_record_type' => $this->dns_record_type,
            'dns_resolver' => $this->dns_resolver,
            'dns_expected' => $this->dns_expected,
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
            'retry_interval' => $this->retry_interval,
            /** `paused` — монитор выключен; `pending` — ещё не проверялся или идут повторные проверки */
            'status' => $this->effectiveStatus(),
            /** С какого момента текущий статус */
            'status_since' => $this->status_since,
            'last_check_at' => $this->last_check_at,
            'last_response_ms' => $this->last_response_ms,
            'last_error' => $this->last_error,
            /** Доля успешных проверок, %; null — нет данных за период */
            'uptime' => $stats['uptime'],
            /** Среднее время ответа за 24 часа, мс */
            'avg_response_ms' => $stats['avg_response_ms'],
            /** Сертификат (только http с https-адресом) */
            'ssl' => $this->ssl_valid_to ? [
                'valid_to' => $this->ssl_valid_to,
                'issuer' => $this->ssl_issuer,
                'days_left' => MonitorStatus::daysLeft($this->ssl_valid_to),
            ] : null,
            /** Последние проверки, старые → новые (до 50) */
            'recent' => $stats['recent'],
            'created_at' => $this->created_at,
        ];
    }
}
