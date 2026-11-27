<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Amendment 7: the reference a player writes on a postal entry, one per player per competition. */
class PostalReference extends Model
{
    /** No 0/O, 1/I/L: easy to write by hand and to read back. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $guarded = ['id'];

    public static function for(User $u, Competition $c): self
    {
        return self::query()->firstOrCreate(['user_id' => $u->id, 'competition_id' => $c->id], ['code' => self::newCode()]);
    }

    /** "pe 7k3m-9q2r" → "PE7K3M9Q2R". */
    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** Shown in pairs of four for writing: "PE7K 3M9Q 2R". */
    public static function pretty(string $code): string
    {
        return trim(chunk_split($code, 4, ' '));
    }

    private static function newCode(): string
    {
        do {
            $code = 'PE';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
