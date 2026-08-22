<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the single user this instance serves. There is no registration route,
 * so this is the only way in.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'mail:user
                            {--name= : Display name}
                            {--email= : Login email}
                            {--password= : Password (prompted if omitted)}';

    protected $description = 'Create or update the login for this instance';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');

        if (strlen((string) $password) < 8) {
            $this->components->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->components->info("{$user->email} can now sign in.");

        return self::SUCCESS;
    }
}
