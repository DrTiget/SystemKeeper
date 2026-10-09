<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\ChannelSender;
use Illuminate\Support\Facades\Mail;

class EmailSender implements ChannelSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        Mail::to($channel->setting('recipients', []))->send(new AlertMail($message));
    }
}
