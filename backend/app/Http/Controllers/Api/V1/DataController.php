<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\User;
use App\Services\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Amendment 2: read-only access to the Platform's data for the Client's own tools (accounting, CRM, BI).
 * Every list is newest-changed last, 100 a page (`?page=`), and takes `?since=<ISO time>` to fetch only what changed.
 * Money is whole pence. Nothing here can change anything; changes go through the admin.
 */
class DataController extends Controller
{
    private function since(Request $r, $q, string $col = 'updated_at')
    {
        if ($s = $r->query('since')) {
            try {
                $q->where($col, '>', Carbon::parse((string) $s)->utc());
            } catch (\Throwable) {
                abort(response()->json(['message' => 'since must be an ISO 8601 time.', 'code' => 'bad_since'], 422));
            }
        }

        return $q->orderBy($col)->orderBy('id');
    }

    private function page($q, callable $map)
    {
        $p = $q->paginate(100);

        return ['data' => collect($p->items())->map($map)->values(), 'page' => $p->currentPage(), 'lastPage' => $p->lastPage(), 'total' => $p->total()];
    }

    private static function t($d): ?string
    {
        return $d?->copy()->utc()->toIso8601ZuluString();
    }

    public function competitions(Request $r)
    {
        return $this->page($this->since($r, Competition::query()), fn (Competition $c) => [
            'id' => $c->id, 'slug' => $c->slug, 'title' => $c->title, 'type' => $c->type,
            'pricePence' => (int) $c->price_pence, 'maxTickets' => (int) $c->max_tickets,
            'opensAt' => self::t($c->opens_at), 'closesAt' => self::t($c->closes_at),
            'publishedAt' => self::t($c->published_at), 'endedAt' => self::t($c->ended_at),
            'endPrize' => $c->end_prize_name, 'endPrizeValuePence' => $c->end_prize_value_pence,
            'prizeCostPence' => $c->prize_cost_pence, 'verityRef' => $c->verity_ref, 'updatedAt' => self::t($c->updated_at),
        ]);
    }

    public function orders(Request $r)
    {
        $q = Order::query()->with('lines');
        if ($s = $r->query('status')) {
            $q->where('status', (string) $s);
        }

        return $this->page($this->since($r, $q), fn (Order $o) => self::order($o));
    }

    public static function order(Order $o): array
    {
        return [
            'number' => $o->number, 'playerId' => $o->user_id, 'type' => $o->order_type, 'status' => $o->status,
            'totalPence' => (int) $o->total_pence, 'cardPence' => (int) $o->card_pence, 'walletPence' => (int) $o->wallet_pence,
            'bonusPence' => (int) $o->paid_bonus_pence, 'discountPence' => (int) $o->discount_pence, 'promoCode' => $o->promo_code,
            'cardType' => $o->card_type, 'channel' => $o->channel,
            'paidAt' => self::t($o->paid_at), 'createdAt' => self::t($o->created_at), 'updatedAt' => self::t($o->updated_at),
            'lines' => $o->relationLoaded('lines') ? $o->lines->map(fn ($l) => [
                'competitionId' => $l->competition_id, 'quantity' => (int) $l->quantity, 'freeQuantity' => (int) $l->free_quantity,
                'unitPence' => (int) $l->unit_pence, 'linePence' => (int) $l->line_pence,
            ])->values() : [],
        ];
    }

    /** Players (no passwords, birth dates, ID checks or protection details: those stay in the admin). */
    public function players(Request $r)
    {
        $q = User::query()->where('is_admin', false)->where('is_staff', false);

        return $this->page($this->since($r, $q), fn (User $u) => self::player($u));
    }

    public static function player(User $u): array
    {
        return [
            'id' => $u->id, 'firstName' => $u->first_name, 'lastName' => $u->last_name, 'email' => $u->email, 'phone' => $u->phone,
            'postcode' => $u->postcode, 'emailVerified' => $u->email_verified_at !== null,
            'marketingEmail' => (bool) $u->marketing_email, 'marketingSms' => (bool) $u->marketing_sms,
            'closed' => $u->closed_at !== null, 'joinedAt' => self::t($u->created_at), 'updatedAt' => self::t($u->updated_at),
        ];
    }

    public function postalEntries(Request $r)
    {
        return $this->page($this->since($r, PostalEntry::query()), fn (PostalEntry $p) => self::postal($p));
    }

    public static function postal(PostalEntry $p): array
    {
        return [
            'id' => $p->id, 'competitionId' => $p->competition_id, 'playerId' => $p->user_id, 'status' => $p->status,
            'reason' => $p->reason, 'source' => $p->source, 'reference' => $p->reference,
            'receivedAt' => self::t($p->received_at), 'createdAt' => self::t($p->created_at),
        ];
    }

    /** The same daily totals as Business Reports' "Daily totals" export, UK days, at most 366 of them. */
    public function dailyTotals(Request $r, Reports $reports)
    {
        try {
            $to = $r->query('to') ? Carbon::parse((string) $r->query('to'), 'Europe/London')->endOfDay() : now('Europe/London')->endOfDay();
            $from = $r->query('from') ? Carbon::parse((string) $r->query('from'), 'Europe/London')->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        } catch (\Throwable) {
            return response()->json(['message' => 'from and to must be dates (YYYY-MM-DD).', 'code' => 'bad_dates'], 422);
        }
        if ($from->gt($to) || $from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Ask for at most 366 days, from before to.', 'code' => 'bad_dates'], 422);
        }

        $keys = ['date', 'salesPence', 'cardPence', 'walletCashAndWinningsPence', 'siteCreditUsedPence', 'promoDiscountsPence',
            'topUpsPence', 'refundsPence', 'paymentFeesEstimatePence', 'withdrawalsPaidPence', 'walletCashEndPence',
            'walletWinningsEndPence', 'siteCreditEndPence'];

        return ['data' => array_map(fn (array $row) => array_combine($keys, array_values($row)), $reports->dailyTotals($from->utc(), $to->utc()))];
    }
}
