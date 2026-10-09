<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

class SystemNetworkProbe implements NetworkProbe
{
    public function tcp(string $host, int $port, int $timeout): void
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new MonitorProbeException("Не удалось подключиться к {$host}:{$port}: ".($errstr ?: "код {$errno}"));
        }
        fclose($socket);
    }

    public function ping(string $host, int $timeout): void
    {
        $result = Process::timeout($timeout + 5)->run(['ping', '-c', '1', '-W', (string) max(1, $timeout), '--', $host]);

        if (! $result->successful()) {
            $output = trim($result->errorOutput() ?: $result->output());
            throw new MonitorProbeException('Ping не прошёл: '.($output !== '' ? strtok($output, "\n") : "код {$result->exitCode()}"));
        }
    }

    public function sslExpiresAt(string $host, int $port, int $timeout): CarbonImmutable
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MonitorProbeException("TLS-соединение с {$host}:{$port} не удалось: ".($errstr ?: "код {$errno}"));
        }

        $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info || ! isset($info['validTo_time_t'])) {
            throw new MonitorProbeException('Не удалось прочитать сертификат');
        }

        return CarbonImmutable::createFromTimestamp($info['validTo_time_t']);
    }
}
