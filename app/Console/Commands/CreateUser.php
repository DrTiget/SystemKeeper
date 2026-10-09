<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('user:create {--name= : Имя} {--email= : Email} {--password= : Пароль (мин. 8 символов)}')]
#[Description('Создать пользователя API')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: $this->ask('Имя'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Пароль'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($data);

        $this->info("Пользователь создан: #{$user->id} {$user->name} <{$user->email}>");

        return self::SUCCESS;
    }
}
