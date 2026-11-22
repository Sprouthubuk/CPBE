<?php

namespace App\Services;

use App\Models\AdminAction;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use Sprouthub\Verity\Models\Winner;

/** Chargebacks and disputes (SOW 2.15): log them, gather the evidence pack, and settle them. */
class Disputes
{
    public function log(User $admin, Order $order, array $d): Dispute
    {
        $dispute = Dispute::query()->create([
            'order_id' => $order->id, 'kind' => $d['kind'] ?? 'chargeback', 'reference' => $d['reference'] ?? null,
            'amount_pence' => (int) ($d['amount_pence'] ?? $order->card_pence), 'reason' => $d['reason'],
            'respond_by' => $d['respond_by'] ?? null, 'notes' => $d['notes'] ?? null, 'logged_by' => $admin->id, 'status' => 'open',
        ]);
        AdminAction::record($admin, 'dispute.logged', (string) $dispute->id, $d['reason'], ['order' => $order->number]);

        return $dispute;
    }

    /** Change the status. A chargeback that's lost or accepted marks the order refunded, which reverses its rewards. */
    public function settle(User $admin, Dispute $d, string $status, ?string $notes): void
    {
        if (! isset(Dispute::STATUSES[$status])) {
            throw new \DomainException('Unknown status.');
        }
        $d->forceFill(['status' => $status, 'notes' => trim(($d->notes ? $d->notes."\n" : '').($notes ?? '')) ?: null,
            'closed_at' => in_array($status, ['won', 'lost', 'accepted'], true) ? now() : null])->save();
        if (in_array($status, ['lost', 'accepted'], true) && $d->order->status === 'paid' && $d->kind === 'chargeback') {
            // Money has gone back to the cardholder: the order counts as refunded (referral and loyalty rewards reverse).
            $d->order->forceFill(['status' => 'refunded'])->save();
        }
        AdminAction::record($admin, 'dispute.'.$status, (string) $d->id, $notes);
    }

    /**
     * Everything the payment provider asks for, for one order: who the player is, when they joined, when and
     * where the order was placed, the tickets issued, skill answers, responsible-play settings, wins and
     * wallet credit used. Shown as a printable page in the admin.
     */
    public function evidence(Order $order): array
    {
        $order->loadMissing('lines.competition', 'user.limits');
        $u = $order->user;
        $owner = $u->ownerRef();
        $wins = Winner::query()->where('owner_ref', $owner)->where('status', 'active')->orderByDesc('id')->limit(50)->get();
        $orders = Order::query()->where('user_id', $u->id)->where('status', 'paid');

        return [
            'player' => [
                'Name' => trim($u->first_name.' '.$u->last_name), 'Email' => $u->email, 'Phone' => $u->phone ?: '—',
                'Date of birth' => $u->date_of_birth?->format('j M Y') ?? '—',
                'Address' => trim(implode(', ', array_filter([$u->address_line1, $u->address_line2, $u->city, $u->postcode]))) ?: '—',
                'Signed up' => $u->created_at?->timezone('Europe/London')->format('j M Y, H:i'),
                'Email confirmed' => $u->email_verified_at?->timezone('Europe/London')->format('j M Y, H:i') ?? 'No',
                'ID checked' => $u->id_verified_at?->timezone('Europe/London')->format('j M Y') ?? 'No',
                'Paid orders' => (string) (clone $orders)->count(),
                'Total paid by card' => '£'.number_format((int) (clone $orders)->sum('card_pence') / 100, 2),
            ],
            'order' => [
                'Order' => $order->number, 'Status' => $order->status,
                'Placed' => $order->created_at?->timezone('Europe/London')->format('j M Y, H:i:s'),
                'Paid' => $order->paid_at?->timezone('Europe/London')->format('j M Y, H:i:s') ?? '—',
                'IP address' => $order->ip ?? '—', 'Card type' => $order->card_type ?? '—',
                'Total' => '£'.number_format($order->total_pence / 100, 2), 'By card' => '£'.number_format($order->card_pence / 100, 2),
                'From wallet' => '£'.number_format($order->wallet_pence / 100, 2).' (cash £'.number_format($order->paid_deposit_pence / 100, 2)
                    .', bonus £'.number_format($order->paid_bonus_pence / 100, 2).', winnings £'.number_format($order->paid_winnings_pence / 100, 2).')',
                'Payment reference' => $order->gateway_ref ?? '—', 'Promo code' => $order->promo_code ?? '—',
            ],
            'lines' => $order->lines->map(fn ($l) => [
                'competition' => $l->competition?->title, 'quantity' => $l->quantity, 'tickets' => implode(', ', array_slice((array) $l->tickets, 0, 200)),
                'skill' => $l->skill_answer !== null ? ($l->competition?->skill_question.' → '.($l->competition?->skill_options[$l->skill_answer] ?? '?')) : '—',
            ])->all(),
            'saferPlay' => [
                'Weekly deposit limit' => $u->limits?->weekly_deposit_pence !== null ? '£'.number_format($u->limits->weekly_deposit_pence / 100, 2) : 'None',
                'Self-excluded until' => $u->self_excluded_until?->format('j M Y') ?? '—',
                'Break until' => $u->cool_off_until?->format('j M Y H:i') ?? '—',
            ],
            'wins' => $wins->map(fn (Winner $w) => ['prize' => $w->prize_name, 'value' => '£'.number_format(((int) $w->prize_value_pence) / 100, 2),
                'at' => $w->created_at?->timezone('Europe/London')->format('j M Y, H:i')])->all(),
            'credit' => LedgerEntry::query()->where('user_id', $u->id)->orderByDesc('id')->limit(30)->get()
                ->map(fn (LedgerEntry $e) => ['at' => $e->created_at?->timezone('Europe/London')->format('j M Y, H:i'), 'what' => $e->reason,
                    'bucket' => $e->bucket, 'amount' => ($e->direction === 'debit' ? '−' : '+').'£'.number_format($e->amount_pence / 100, 2)])->all(),
        ];
    }
}
