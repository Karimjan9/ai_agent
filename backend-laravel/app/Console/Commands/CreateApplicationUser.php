<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateApplicationUser extends Command
{
    protected $signature = 'auth:create-user {email} {--name=Administrator} {--role=admin}';
    protected $description = 'Create or rotate a NeuroTrader web user without exposing a password in process arguments';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! in_array($role, ['viewer', 'operator', 'admin'], true)) {
            $this->components->error('Role must be viewer, operator, or admin.');
            return self::FAILURE;
        }
        $password = (string) (env('BOOTSTRAP_ADMIN_PASSWORD') ?: $this->secret('Password (minimum 12 characters)'));
        if (strlen($password) < 12) {
            $this->components->error('Password must contain at least 12 characters.');
            return self::FAILURE;
        }
        $user = User::updateOrCreate(['email' => strtolower((string) $this->argument('email'))], [
            'name' => (string) $this->option('name'), 'role' => $role, 'is_active' => true, 'password' => $password,
        ]);
        $this->components->info("User #{$user->id} ready with role {$role}.");
        return self::SUCCESS;
    }
}
