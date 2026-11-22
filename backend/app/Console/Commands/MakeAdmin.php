<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Give an account admin access with a role (anyone in the admin team can't play). Run on the server: php artisan platform:make-admin you@example.com */
class MakeAdmin extends Command
{
    protected $signature = 'platform:make-admin {email} {--first=Admin} {--last=User} {--role=admin : admin, manager, finance, marketing or support}';

    protected $description = 'Create or promote an admin account';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $role = (string) $this->option('role');
        if (! isset(\App\Support\Roles::ROLES[$role])) {
            $this->error('Role must be one of: '.implode(', ', array_keys(\App\Support\Roles::ROLES)).'.');

            return self::FAILURE;
        }
        $u = User::query()->where('email', $email)->first();
        if (! $u) {
            $password = $this->secret('Password for the new admin (12+ characters)');
            if (strlen((string) $password) < 12) {
                $this->error('Use at least 12 characters.');

                return self::FAILURE;
            }
            $u = new User;
            $u->forceFill(['email' => $email, 'first_name' => $this->option('first'), 'last_name' => $this->option('last'), 'password' => $password, 'email_verified_at' => now()]);
        }
        $u->forceFill(['is_admin' => true, 'admin_role' => $role, 'is_staff' => true, 'email_verified_at' => $u->email_verified_at ?? now()])->save();
        $this->info("{$email} is in the admin team as {$role}. Sign in at /admin.");

        return self::SUCCESS;
    }
}
