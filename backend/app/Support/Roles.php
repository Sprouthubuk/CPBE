<?php

namespace App\Support;

use App\Models\User;

/**
 * Admin and staff roles (SOW 2.14, 2.16). Everyone who can open the admin has is_admin; their admin_role decides
 * which areas they see. "admin" sees everything, including settings and the team. No one with a role can play.
 */
final class Roles
{
    public const ROLES = [
        'admin' => 'Admin (everything)',
        'manager' => 'Manager',
        'finance' => 'Finance',
        'marketing' => 'Marketing',
        'support' => 'Customer support',
    ];

    /** Area => roles that can open it ("admin" can open everything). */
    public const AREAS = [
        'competitions' => ['manager'],
        'orders' => ['manager', 'finance', 'support'],
        'players' => ['manager', 'support'],
        'verification' => ['manager', 'support'],
        'postal' => ['manager', 'support'],
        'messages' => ['manager', 'support'],
        'withdrawals' => ['finance'],
        'finance' => ['finance', 'manager'],
        'marketing' => ['manager', 'marketing'],
        'content' => ['manager', 'marketing'],
        'reports' => ['manager', 'finance', 'marketing'],
        'settings' => [],
        'team' => [],
    ];

    public static function of(?User $u): ?string
    {
        if (! $u || ! $u->is_admin) {
            return null;
        }

        return isset(self::ROLES[$u->admin_role ?? '']) ? $u->admin_role : 'admin';
    }

    public static function can(?User $u, string $area): bool
    {
        $role = self::of($u);
        if ($role === null) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        if (! isset(self::AREAS[$area])) {
            throw new \InvalidArgumentException("Unknown admin area {$area}.");
        }

        return in_array($role, self::AREAS[$area], true);
    }

    /** For Filament: the signed-in user can open this area. */
    public static function allows(string $area): bool
    {
        return self::can(auth()->user(), $area);
    }
}
