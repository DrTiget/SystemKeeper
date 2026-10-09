<?php

namespace App\Services\Alerts;

use RuntimeException;

/** Канал сознательно ничего не отправил (например, драйвер выключен): повторять не нужно. */
class AlertSkippedException extends RuntimeException {}
