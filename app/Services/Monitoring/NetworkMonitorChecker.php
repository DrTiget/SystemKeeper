<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Настоящие проверки по сети. Заголовки и тело запроса в сообщения и журнал не попадают;
 * из текстов ошибок вырезаются логин и пароль из адреса.
 */
class NetworkMonitorChecker implements MonitorChecker
{
    public function check(Monitor $monitor): CheckResult
    {
        try {
            return match ($monitor->type) {
                Monitor::HTTP => $this->http($monitor),
                Monitor::TCP => $this->tcp($monitor),
                Monitor::PING => $this->ping($monitor),
                Monitor::DNS => $this->dns($monitor),
                default => CheckResult::down('неизвестный тип монитора'),
            };
        } catch (Throwable $e) {
            return CheckResult::down($this->clean($e->getMessage()));
        }
    }

    private function http(Monitor $monitor): CheckResult
    {
        $started = hrtime(true);
        $request = Http::timeout($monitor->timeout)
            ->withHeaders(collect($monitor->headers ?? [])->mapWithKeys(fn ($h) => [$h['name'] => $h['value']])->all())
            ->withOptions(['allow_redirects' => $monitor->follow_redirects ? ['max' => 10] : false]);
        if ($monitor->ignore_tls) {
            $request = $request->withoutVerifying();
        }
        if ($monitor->body !== null && $monitor->body !== '') {
            $request = $request->withBody($monitor->body, 'text/plain');
        }

        try {
            $response = $request->send($monitor->method, $monitor->url);
        } catch (ConnectionException $e) {
            return CheckResult::down($this->clean($e->getMessage()), $this->elapsed($started));
        }
        $ms = $this->elapsed($started);
        $code = $response->status();
        $reason = trim($code.' '.$response->reason());

        $cert = null;
        if ($monitor->ssl_expiry_days !== null && str_starts_with(strtolower($monitor->url), 'https://')) {
            $cert = $this->certificate($monitor->url, $monitor->timeout);
        }

        $result = $this->judgeResponse($monitor, $response, $reason, $ms);
        if ($cert) {
            $left = MonitorStatus::daysLeft($cert['valid_to']);
            if ($left < 0 && $result->ok && ! $monitor->ignore_tls) {
                $result = CheckResult::down('сертификат истёк '.$cert['valid_to']->format('d.m.Y'), $ms, $code);
            } elseif ($result->ok && $left < $monitor->ssl_expiry_days) {
                $result = CheckResult::up($ms, "{$reason}; сертификат истекает через {$left} дн.", $code);
            }
            $result = $result->withCertificate($cert['valid_to'], $cert['issuer']);
        }

        return $result;
    }

    private function judgeResponse(Monitor $monitor, Response $response, string $reason, int $ms): CheckResult
    {
        $code = $response->status();
        if (! $this->accepted($code, $monitor->accepted_statuses ?? ['200-299'])) {
            return CheckResult::down("неожиданный код ответа: {$reason}", $ms, $code);
        }
        if ($monitor->keyword !== null && $monitor->keyword !== '') {
            $found = str_contains($response->body(), $monitor->keyword);
            if ($found === $monitor->keyword_invert) {
                return CheckResult::down($monitor->keyword_invert ? 'на странице есть запрещённое слово' : 'нет слова на странице', $ms, $code);
            }
        }

        return CheckResult::up($ms, $reason, $code);
    }

    /**
     * @param  list<string>  $accepted  «200-299», «301»
     */
    public function accepted(int $code, array $accepted): bool
    {
        foreach ($accepted as $rule) {
            [$from, $to] = array_pad(array_map('intval', explode('-', trim($rule), 2)), 2, null);
            if ($code >= $from && $code <= ($to ?? $from)) {
                return true;
            }
        }

        return false;
    }

    private function tcp(Monitor $monitor): CheckResult
    {
        $started = hrtime(true);
        $error = $this->tcpConnect($monitor->hostname, (int) $monitor->port, $monitor->timeout);

        return $error === null
            ? CheckResult::up($this->elapsed($started), "порт {$monitor->port} открыт")
            : CheckResult::down($error, $this->elapsed($started));
    }

    private function ping(Monitor $monitor): CheckResult
    {
        $started = hrtime(true);
        $r = Process::timeout($monitor->timeout + 2)->run(['ping', '-c', '1', '-W', (string) max(1, $monitor->timeout), '--', $monitor->hostname]);
        $ms = $this->elapsed($started);
        if (! $r->successful()) {
            return CheckResult::down('хост не отвечает на ping', $ms);
        }
        if (preg_match('/time[=<]([\d.]+)\s*ms/', $r->output(), $m)) {
            $ms = (int) round((float) $m[1]);
        }

        return CheckResult::up($ms, 'ping успешен');
    }

    private function dns(Monitor $monitor): CheckResult
    {
        $started = hrtime(true);
        $values = $this->dnsLookup($monitor->hostname, $monitor->dns_record_type, $monitor->dns_resolver, $monitor->timeout);
        $ms = $this->elapsed($started);
        if ($values === []) {
            return CheckResult::down("записи {$monitor->dns_record_type} не найдены", $ms);
        }
        $expected = trim((string) $monitor->dns_expected);
        if ($expected !== '' && ! collect($values)->contains(fn ($v) => strcasecmp(rtrim($v, '.'), rtrim($expected, '.')) === 0)) {
            return CheckResult::down('значение записи не совпало: '.mb_substr(implode(', ', $values), 0, 300), $ms);
        }

        return CheckResult::up($ms, mb_substr(implode(', ', $values), 0, 300));
    }

    /** Текст ошибки или null, если порт открыт. */
    protected function tcpConnect(string $host, int $port, int $timeout): ?string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if ($socket === false) {
            return $errstr !== '' ? $errstr : "порт {$port} недоступен";
        }
        fclose($socket);

        return null;
    }

    /**
     * Значения записей: A/AAAA — адреса, CNAME/NS — цели, MX — хосты, TXT — тексты.
     *
     * @return list<string>
     */
    protected function dnsLookup(string $host, string $type, ?string $resolver, int $timeout): array
    {
        if ($resolver) {
            $r = Process::timeout($timeout + 2)->run(['dig', '+short', "+time={$timeout}", '+tries=1', "@{$resolver}", $host, $type]);
            if (! $r->successful()) {
                throw new \RuntimeException('DNS-сервер не ответил');
            }

            return array_values(array_filter(array_map(fn ($l) => trim($l, " \t\"\r"), explode("\n", $r->output()))));
        }

        $records = @dns_get_record($host, constant('DNS_'.$type)) ?: [];

        return array_values(array_filter(array_map(fn ($r) => match ($type) {
            'A' => $r['ip'] ?? null,
            'AAAA' => $r['ipv6'] ?? null,
            'CNAME', 'NS' => $r['target'] ?? null,
            'MX' => $r['target'] ?? null,
            'TXT' => $r['txt'] ?? null,
        }, $records)));
    }

    /**
     * Сертификат сервера по https-адресу (без проверки цепочки — важен только срок).
     *
     * @return array{valid_to: Carbon, issuer: ?string}|null
     */
    protected function certificate(string $url, int $timeout): ?array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (! $host) {
            return null;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client('ssl://'.$host.':'.($parts['port'] ?? 443), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            return null;
        }
        $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info || ! isset($info['validTo_time_t'])) {
            return null;
        }

        return [
            'valid_to' => Carbon::createFromTimestampUTC($info['validTo_time_t']),
            'issuer' => $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null,
        ];
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** Без логина и пароля из адреса, в одну строку и не длиннее 500 символов. */
    private function clean(string $message): string
    {
        $message = preg_replace('#(https?://)[^/\s@]+@#i', '$1', $message);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, 500);
    }
}
