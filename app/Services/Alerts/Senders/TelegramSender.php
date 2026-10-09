<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\ChannelSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramSender implements ChannelSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $token = (string) $channel->setting('bot_token');

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $channel->setting('chat_id'),
                'text' => $message->text(),
            ]);
        } catch (ConnectionException $e) {
            // Адрес запроса содержит токен — в ошибку он попасть не должен
            throw new RuntimeException('Telegram недоступен: '.str_replace($token, '***', $e->getMessage()));
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram: '.($response->json('description') ?: 'HTTP '.$response->status()));
        }
    }
}
