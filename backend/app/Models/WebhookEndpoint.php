<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Amendment 2: where the Platform sends its events, signed with the endpoint's secret. */
class WebhookEndpoint extends Model
{
    public const EVENTS = [
        'order.paid' => 'An order is paid (tickets, top-up or membership)',
        'order.refunded' => 'An order is refunded',
        'player.registered' => 'A player joins',
        'postal.entered' => 'A free postal entry is entered',
        'competition.published' => 'A competition is published',
        'competition.drawn' => 'A draw has its winners',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            if (! str_starts_with((string) $e->url, 'https://') || ! filter_var($e->url, FILTER_VALIDATE_URL)) {
                throw new \DomainException('A webhook address must start with https://.');
            }
            $e->secret ??= bin2hex(random_bytes(24));
        });
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
