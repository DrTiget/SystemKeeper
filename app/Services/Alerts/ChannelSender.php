<?php

namespace App\Services\Alerts;

use App\Mail\AlertMail;
use App\Models\AlertChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Отправляет сообщение в канал: Telegram (Bot API), письмо или вебхук (JSON с подписью X-Signature).
 * Ошибка — RuntimeException с понятным текстом; секреты (токен бота, секрет вебхука) в тексте ошибки вырезаются.
 */
class ChannelSender
{
    private const TIMEOUT = 10;

    /**
     * @param  array<string, mixed>  $payload  для вебхука: машиночитаемое описание события
     *
     * @throws RuntimeException
     */
    public function send(AlertChannel $channel, AlertMessage $message, array $payload = []): void
    {
        $config = $channel->config ?? [];
        try {
            match ($channel->type) {
                AlertChannel::TELEGRAM => $this->telegram($config, $message),
                AlertChannel::EMAIL => $this->email($config, $message),
                AlertChannel::WEBHOOK => $this->webhook($config, $message, $payload),
                default => throw new RuntimeException('Неизвестный тип канала'),
            };
        } catch (Throwable $e) {
            throw new RuntimeException($this->scrub($e->getMessage(), $config), previous: $e);
        }
    }

    /** @param array<string, mixed> $config */
    private function telegram(array $config, AlertMessage $message): void
    {
        $response = Http::timeout(self::TIMEOUT)->asJson()->post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", [
            'chat_id' => $config['chat_id'] ?? '',
            'text' => mb_substr($message->text(), 0, 4000),
            'disable_web_page_preview' => true,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram: '.($response->json('description') ?: 'ответ '.$response->status()));
        }
    }

    /** @param array<string, mixed> $config */
    private function email(array $config, AlertMessage $message): void
    {
        $recipients = array_values((array) ($config['recipients'] ?? []));
        if ($recipients === []) {
            throw new RuntimeException('Не указаны получатели');
        }

        Mail::to($recipients)->send(new AlertMail($message));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $config, AlertMessage $message, array $payload): void
    {
        $body = json_encode([
            ...$payload,
            'title' => $message->title,
            'text' => $message->text(),
            'url' => $message->url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['User-Agent' => 'SystemKeeper'];
        if (filled($config['secret'] ?? null)) {
            $headers['X-Signature'] = hash_hmac('sha256', $body, (string) $config['secret']);
        }

        $response = Http::timeout(self::TIMEOUT)->withHeaders($headers)->withBody($body, 'application/json')->post((string) ($config['url'] ?? ''));
        if (! $response->successful()) {
            throw new RuntimeException('Вебхук ответил кодом '.$response->status());
        }
    }

    /** @param array<string, mixed> $config */
    private function scrub(string $text, array $config): string
    {
        foreach (['bot_token', 'secret'] as $key) {
            if (filled($config[$key] ?? null)) {
                $text = str_replace((string) $config[$key], '***', $text);
            }
        }

        return mb_substr($text, 0, 500);
    }
}
