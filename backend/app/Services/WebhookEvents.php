<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\DataController;
use App\Models\Competition;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Sprouthub\Verity\Events\CompetitionDrawn;

/** Amendment 2: which changes become webhook events, and what each carries (the same shapes as /api/v1). */
final class WebhookEvents
{
    public static function register(): void
    {
        Order::updated(function (Order $o) {
            if ($o->wasChanged('status') && in_array($o->status, ['paid', 'refunded'], true)) {
                Webhooks::fire('order.'.$o->status, DataController::order($o->loadMissing('lines')));
            }
        });
        User::created(function (User $u) {
            if (! $u->is_admin && ! $u->is_staff) {
                Webhooks::fire('player.registered', DataController::player($u));
            }
        });
        PostalEntry::saved(function (PostalEntry $p) {
            if ($p->status === 'entered' && ($p->wasRecentlyCreated ? true : $p->wasChanged('status'))) {
                Webhooks::fire('postal.entered', DataController::postal($p));
            }
        });
        Event::listen(CompetitionDrawn::class, function (CompetitionDrawn $e) {
            if ($c = Competition::query()->where('verity_ref', $e->competition->ref)->first()) {
                Webhooks::fire('competition.drawn', self::competition($c) + ['verifyUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/verify/'.$c->verity_ref]);
            }
        });
    }

    public static function competition(Competition $c): array
    {
        return ['id' => $c->id, 'slug' => $c->slug, 'title' => $c->title, 'type' => $c->type, 'verityRef' => $c->verity_ref,
            'pricePence' => (int) $c->price_pence, 'maxTickets' => (int) $c->max_tickets,
            'closesAt' => $c->closes_at?->copy()->utc()->toIso8601ZuluString()];
    }
}
