<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Настройки уведомлений (почта SMTP, адрес Telegram Bot API и прокси к нему) — в таблице `settings`, пароли шифруются.
 * Почта: если в настройках задан хост — SMTP из них, иначе MAIL_* из .env (когда MAIL_MAILER не log/array).
 */
class NotificationSettings
{
    public const KEY = 'notifications';

    public const MAILER = 'settings';

    public const TELEGRAM_API = 'https://api.telegram.org';

    public const NOT_CONFIGURED = 'Почта не настроена: Настройки → Уведомления';

    private const TIMEOUT = 15;

    private const DEFAULTS = [
        'mail' => ['host' => null, 'port' => 587, 'encryption' => 'tls', 'username' => null, 'password' => null, 'from_address' => null, 'from_name' => null],
        'telegram' => ['api_url' => null, 'proxy' => ['enabled' => false, 'type' => 'http', 'host' => null, 'port' => null, 'username' => null, 'password' => null]],
    ];

    /** Сохранённые настройки с подставленными значениями по умолчанию (с паролями — наружу не отдавать). */
    public function all(): array
    {
        $stored = Setting::query()->where('key', self::KEY)->first()?->value ?? [];

        return [
            'mail' => array_replace(self::DEFAULTS['mail'], (array) ($stored['mail'] ?? [])),
            'telegram' => [
                'api_url' => $stored['telegram']['api_url'] ?? null,
                'proxy' => array_replace(self::DEFAULTS['telegram']['proxy'], (array) ($stored['telegram']['proxy'] ?? [])),
            ],
        ];
    }

    /** @param array<string, mixed> $settings полный набор, как из all() */
    public function save(array $settings): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => $settings]);
    }

    /**
     * Наложить принятые поля на сохранённые настройки. Пароль: нет поля или пусто — прежний остаётся,
     * `clear_password: true` — стирается. Пустые текстовые поля сбрасываются.
     *
     * @param  array<string, mixed>  $input  провалидированные `mail` и/или `telegram`
     */
    public function merge(array $input): array
    {
        $current = $this->all();

        $mail = (array) ($input['mail'] ?? []);
        $current['mail'] = $this->fill($current['mail'], $mail, ['host', 'username', 'from_address', 'from_name']);
        foreach (['port' => 587, 'encryption' => 'tls'] as $key => $default) {
            if (array_key_exists($key, $mail)) {
                $current['mail'][$key] = $mail[$key] ?? $default;
            }
        }

        $telegram = (array) ($input['telegram'] ?? []);
        if (array_key_exists('api_url', $telegram)) {
            $current['telegram']['api_url'] = filled($telegram['api_url']) ? rtrim($telegram['api_url'], '/') : null;
        }
        $proxy = (array) ($telegram['proxy'] ?? []);
        $current['telegram']['proxy'] = $this->fill($current['telegram']['proxy'], $proxy, ['host', 'username']);
        foreach (['enabled' => false, 'type' => 'http', 'port' => null] as $key => $default) {
            if (array_key_exists($key, $proxy)) {
                $current['telegram']['proxy'][$key] = $proxy[$key] ?? $default;
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $keys  текстовые поля раздела
     * @return array<string, mixed>
     */
    private function fill(array $section, array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $section[$key] = filled($input[$key]) ? $input[$key] : null;
            }
        }
        if (filled($input['password'] ?? null)) {
            $section['password'] = $input['password'];
        } elseif (! empty($input['clear_password'])) {
            $section['password'] = null;
        }

        return $section;
    }

    /** `settings` — SMTP из настроек, `env` — из .env, `none` — почта не настроена (письма никуда не уходят). */
    public function mailSource(): string
    {
        return match (true) {
            filled($this->all()['mail']['host']) => 'settings',
            ! in_array(config('mail.default'), ['log', 'array', null], true) => 'env',
            default => 'none',
        };
    }

    public function mailConfigured(): bool
    {
        return $this->mailSource() !== 'none';
    }

    /**
     * Отправить письмо получателям: через SMTP из настроек (с адресом отправителя из них) или обычным мейлером из .env.
     *
     * @param  array<int, string>|string  $to
     *
     * @throws RuntimeException если почта не настроена
     */
    public function sendMail(array|string $to, Mailable $mailable): void
    {
        switch ($this->mailSource()) {
            case 'settings':
                $mail = $this->all()['mail'];
                config(['mail.mailers.'.self::MAILER => $this->smtpConfig($mail)]);
                Mail::purge(self::MAILER);
                if (filled($mail['from_address'])) {
                    $mailable->from($mail['from_address'], $mail['from_name'] ?: null);
                }
                Mail::mailer(self::MAILER)->to($to)->send($mailable);
                break;
            case 'env':
                Mail::to($to)->send($mailable);
                break;
            default:
                throw new RuntimeException(self::NOT_CONFIGURED);
        }
    }

    /** @param array<string, mixed> $mail */
    private function smtpConfig(array $mail): array
    {
        $config = [
            'transport' => 'smtp',
            'host' => $mail['host'],
            'port' => (int) $mail['port'],
            'username' => $mail['username'] ?: null,
            'password' => $mail['password'] ?: null,
            'timeout' => self::TIMEOUT,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];

        // ssl — шифрование с первого байта (smtps, обычно порт 465); tls — обязательный STARTTLS; none — без шифрования
        return $config + match ($mail['encryption']) {
            'ssl' => ['scheme' => 'smtps'],
            'tls' => ['scheme' => 'smtp', 'require_tls' => true],
            default => ['scheme' => 'smtp', 'auto_tls' => false],
        };
    }

    /** Адрес метода Bot API: `{api_url}/{path}`. */
    public function telegramUrl(string $path): string
    {
        return rtrim($this->all()['telegram']['api_url'] ?: self::TELEGRAM_API, '/').'/'.ltrim($path, '/');
    }

    /** HTTP-клиент для Telegram: таймаут и прокси из настроек. */
    public function telegramHttp(): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT)->withoutRedirecting();
        $proxy = $this->proxyUrl();

        return $proxy === null ? $request : $request->withOptions(['proxy' => $proxy]);
    }

    /** `http://user:pass@host:port` или `socks5h://…` (имена резолвит прокси); null — прокси выключен. */
    public function proxyUrl(): ?string
    {
        $proxy = $this->all()['telegram']['proxy'];
        if (! $proxy['enabled'] || blank($proxy['host'])) {
            return null;
        }

        $auth = '';
        if (filled($proxy['username'])) {
            $auth = rawurlencode($proxy['username']).(filled($proxy['password']) ? ':'.rawurlencode($proxy['password']) : '').'@';
        }
        $host = str_contains($proxy['host'], ':') && ! str_starts_with($proxy['host'], '[') ? "[{$proxy['host']}]" : $proxy['host'];

        return ($proxy['type'] === 'socks5' ? 'socks5h' : 'http').'://'.$auth.$host.(filled($proxy['port']) ? ':'.$proxy['port'] : '');
    }

    /**
     * Проверить доступность адреса Bot API (через прокси, если он включён): любой HTTP-ответ — успех.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function checkTelegram(): array
    {
        try {
            $this->telegramHttp()->get($this->telegramUrl(''));
        } catch (Throwable $e) {
            $message = $e instanceof ConnectionException ? $e->getMessage() : 'Не удалось подключиться: '.$e->getMessage();

            return ['ok' => false, 'error' => $this->scrub($message) ?: 'Не удалось подключиться'];
        }

        return ['ok' => true, 'error' => null];
    }

    /** Вырезает из текста пароли SMTP и прокси (и адрес прокси с логином), укорачивает до 500 символов. */
    public function scrub(string $text): string
    {
        $settings = $this->all();
        $proxyPassword = $settings['telegram']['proxy']['password'];
        // сначала самое длинное: адрес прокси целиком, потом сами пароли
        $secrets = [
            filled($proxyPassword) ? $this->proxyUrl() : null,
            $proxyPassword,
            filled($proxyPassword) ? rawurlencode($proxyPassword) : null,
            $settings['mail']['password'],
        ];

        foreach (array_unique(array_filter($secrets, 'filled')) as $secret) {
            $text = str_replace((string) $secret, '***', $text);
        }

        return mb_substr($text, 0, 500);
    }
}
