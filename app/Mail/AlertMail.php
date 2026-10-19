<?php

namespace App\Mail;

use App\Services\Alerts\AlertMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо об алерте: HTML (resources/views/mail/alert.blade.php) и текстовая версия (alert-text.blade.php).
 */
class AlertMail extends Mailable
{
    public function __construct(public AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert->subject());
    }

    public function content(): Content
    {
        return new Content(html: 'mail.alert', text: 'mail.alert-text');
    }
}
