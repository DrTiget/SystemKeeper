<?php

namespace App\Exceptions\Ssh;

/** Внутренний сбой PHP или SSH-библиотеки (не сеть и не ответ сервера): не повод считать сервер недоступным. */
class SshInternalException extends SshConnectionException {}
