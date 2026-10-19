<?php

namespace App\Mail;

use App\Services\Alerts\AlertMessage;

/**
 * Тестовое письмо из «Настройки → Уведомления»: то же оформление, что у уведомлений об алертах.
 */
class TestMail extends AlertMail
{
    public function __construct()
    {
        parent::__construct(AlertMessage::mailCheck());
    }
}
