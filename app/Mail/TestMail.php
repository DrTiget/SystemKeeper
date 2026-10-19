<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Тестовое письмо из «Настройки → Уведомления» (шаблон resources/views/mail/test.blade.php).
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: '[SystemKeeper] Тестовое письмо');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test', with: ['url' => rtrim((string) config('app.url'), '/') ?: null]);
    }
}
