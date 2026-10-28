<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'kadrelio:admin {email} {--name=} {--password=}';

    protected $description = 'Tworzy administratora lub nadaje tę rolę istniejącemu kontu.';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $user = User::where('email', $email)->first();
        if (! $user) {
            $data = ['email' => $email, 'name' => $this->option('name'), 'password' => $this->option('password')];
            $validator = Validator::make($data, ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', Password::min(10)->letters()->numbers()]]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }
            $user = new User($data);
        }
        $user->role = 'admin';
        $user->save();
        $this->info('Konto '.$email.' ma rolę administratora.');

        return self::SUCCESS;
    }
}
