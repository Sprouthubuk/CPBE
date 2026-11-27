<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Amendment 2: a read-only API token for the Client's own tools. Only its SHA-256 is stored. */
class ApiToken extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Make a token: returns [model, the token to show once]. */
    public static function issue(string $name, User $admin): array
    {
        $plain = 'cpk_'.Str::random(40);
        $t = self::query()->create(['name' => $name, 'token_hash' => hash('sha256', $plain), 'hint' => substr($plain, -6), 'created_by' => $admin->id]);

        return [$t, $plain];
    }

    public static function findActive(?string $plain): ?self
    {
        return $plain ? self::query()->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first() : null;
    }
}
