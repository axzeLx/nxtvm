<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'nxtvm:user {username} {--admin : Grant the admin role} {--name= : Display name}';

    protected $description = 'Create a local user or reset an existing user\'s password';

    public function handle(): int
    {
        $username = Str::lower($this->argument('username'));
        $password = $this->secret('Password (min. 10 characters)');

        if (strlen((string) $password) < 10 || $password !== $this->secret('Repeat password')) {
            $this->error('Passwords are too short or do not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['username' => $username]);
        $user->name = $this->option('name') ?: ($user->name ?: $username);
        $user->password = $password;
        $user->is_active = true;
        if ($this->option('admin') || ! $user->exists) {
            $user->role = $this->option('admin') ? User::ROLE_ADMIN : User::ROLE_VIEWER;
        }
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." user '{$username}' ({$user->role}).");

        return self::SUCCESS;
    }
}
