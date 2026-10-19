<?php

namespace App\Mail;

use App\Services\Alerts\AlertMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Текстовое письмо об алерте (шаблон resources/views/mail/alert.blade.php).
 */
class AlertMail extends Mailable
{
    public function __construct(public AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[SystemKeeper] '.$this->alert->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert');
    }
}
