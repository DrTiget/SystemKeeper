<?php

namespace App\Services\Backup;

final readonly class BackupCommand
{
    /**
     * @param  string  $command  shell-конвейер; stdout — сжатый архив (zstd)
     * @param  array<string, string>  $env  переменные окружения (пароли БД), не попадающие в командную строку
     * @param  list<string>  $secrets  значения, которые нужно вырезать из логов и ошибок
     */
    public function __construct(
        public string $command,
        public array $env = [],
        public array $secrets = [],
    ) {}

    /**
     * Команда для выполнения на удалённом сервере: переменные окружения передаются префиксом `env`.
     */
    public function forRemote(): string
    {
        if ($this->env === []) {
            return $this->command;
        }

        $assignments = implode(' ', array_map(
            fn (string $name, string $value) => $name.'='.escapeshellarg($value),
            array_keys($this->env),
            $this->env,
        ));

        return 'env '.$assignments.' '.$this->command;
    }
}
