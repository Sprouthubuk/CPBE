<?php

namespace App\Services;

use App\Models\BundleTier;
use App\Models\Competition;
use App\Models\VaultCase;
use App\Models\PromoCode;
use App\Models\User;

/**
 * Prices a basket from the database only. The browser sends competition ids with a ticket quantity
 * or a bundle id; everything else (price, tickets, availability, discount, what the wallet covers) is
 * decided here. Players never see how many tickets are left: a problem says "choose fewer", not a count.
 */
final class Pricing
{
    public const MAX_LINES = 20;

    public function __construct(private CompetitionState $state, private Wallet $wallet, private PlayCheck $check, private Bundles $bundles) {}

    /**
     * @param  array<int, array{competitionId:string, quantity?:int, bundleId?:int|null}>  $lines
     * @return array<string, mixed> PricedBasket (docs/api.md) plus 'competitions' (id => Competition) for checkout
     */
    public function price(array $lines, ?User $user, ?string $promoCode): array
    {
        // A member can buy within their early-access hours before a competition opens (SOW 2.12).
        return $this->state->forPlayer($user, fn () => $this->priceNow($lines, $user, $promoCode));
    }

    private function priceNow(array $lines, ?User $user, ?string $promoCode): array
    {
        // Singles are merged per competition; a competition carries at most one bundle (the last one chosen).
        $singles = [];
        $bundleIds = [];
        $boxIds = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $l) {
            $id = (string) $l['competitionId'];
            if (! empty($l['boxId'])) {
                $boxIds[$id] = (string) $l['boxId'];
            }
            if (! empty($l['bundleId'])) {
                $bundleIds[$id] = (int) $l['bundleId'];
            } else {
                $singles[$id] = ($singles[$id] ?? 0) + (int) ($l['quantity'] ?? 0);
            }
        }
        $ids = array_values(array_unique([...array_keys($singles), ...array_keys($bundleIds)]));
        // Vault Cases in the basket: their own holds don't count against them, and their bonus tickets apply
        // while the basket still holds what the box packed (a released or paid box gives no bonus).
        $boxes = $boxIds ? VaultCase::query()->whereIn('id', array_unique($boxIds))->whereNotIn('status', ['released', 'paid'])->get()->keyBy('id') : collect();
        $this->state->ignoreBoxes($boxes->keys()->all());
        $competitions = Competition::query()->with('bundleTiers')->whereIn('id', $ids)->get()->keyBy('id');
        $owner = $user?->ownerRef();
        $out = [];
        $problems = [];
        $subtotal = 0;
        $singlesSubtotal = 0;
        $count = 0;

        foreach ($ids as $id) {
            $c = $competitions->get($id);
            if (! $c || ! $c->verity_ref) {
                $problems[] = 'A competition in your basket is no longer available.';

                continue;
            }
            $max = $this->state->canBuy($c, $owner);
            $status = $this->state->status($c);
            $closed = match (true) {
                $status === 'sold_out' => 'Sold out.',
                $status !== 'live' => 'This competition is closed.',
                $max === 0 && $this->state->left($c) - $this->state->reserved($c) <= 0 => 'The last tickets are being paid for right now. Try again in a few minutes.',
                $max === 0 => "You can't buy more tickets for this competition.",
                default => null,
            };
            $room = $max;
            $offers = $closed ? [] : $this->bundles->available($c, max(0, $room));

            if (isset($bundleIds[$id])) {
                $offer = collect($offers)->firstWhere('tier.id', $bundleIds[$id]);
                $tier = $offer['tier'] ?? BundleTier::query()->find($bundleIds[$id]);
                $paid = $offer['paid'] ?? (int) ($tier?->paid_qty ?? 0);
                $free = $offer['free'] ?? (int) ($tier?->free_qty ?? 0);
                $problem = $closed ?? ($offer ? null : 'This bundle is no longer available. Please choose a smaller one.');
                $line = $paid * $c->price_pence;
                $out[] = $this->line($c, $paid + $free, $line, $problem, ['bundleId' => $tier?->id, 'bundleLabel' => $offer['label'] ?? $tier?->displayLabel(), 'paidQuantity' => $paid, 'freeQuantity' => $free])
                    + ['upgrade' => $problem ? null : $this->upgrade($c, $offers, $paid, $paid + $free)];
                $subtotal += $line;
                $count += $paid + $free;
                $room -= $paid + $free;
            }
            if (isset($singles[$id])) {
                $qty = $singles[$id];
                $problem = $closed ?? match (true) {
                    $qty < 1 => 'Choose at least one ticket.',
                    $qty > $room => 'Not that many tickets are left. Please choose fewer.',
                    // The minimum order for the competition (not for a line beside a bundle).
                    ! isset($bundleIds[$id]) && $qty < (int) ($c->min_tickets ?: 1) && $qty < $room => 'The minimum for this competition is '.number_format((int) $c->min_tickets).' tickets.',
                    default => null,
                };
                $line = $qty * $c->price_pence;
                $out[] = $this->line($c, $qty, $line, $problem, ['bundleId' => null, 'bundleLabel' => null, 'paidQuantity' => $qty, 'freeQuantity' => 0])
                    + ['upgrade' => $problem || isset($bundleIds[$id]) ? null : $this->upgrade($c, $offers, $qty, $qty)];
                $subtotal += $line;
                $singlesSubtotal += $line;
                $count += $qty;
            }
            $wanted = ! $closed && isset($boxIds[$id]) ? $this->boxBonus($boxes->get($boxIds[$id]), $c, $bundleIds[$id] ?? null, $singles[$id] ?? 0, max(0, $room - ($singles[$id] ?? 0))) : 0;
            // The draw's promo ticket cap (PromoCap): paid tickets are still sold, the bonus stops.
            $bonus = $wanted > 0 ? min($wanted, PromoCap::room($c)) : 0;
            // On the bundle line if there is one, otherwise the single tickets.
            $k = array_key_last($out);
            if (isset($bundleIds[$id]) && isset($singles[$id])) {
                $k--;
            }
            if ($wanted > $bonus) {
                $out[$k]['notice'] = PromoCap::FULL_MESSAGE;
            }
            if ($bonus > 0) {
                $out[$k]['quantity'] += $bonus;
                $out[$k]['bonusQuantity'] = $bonus;
                $out[$k]['source'] = 'vault_case';
                $out[$k]['vaultCaseId'] = $boxIds[$id];
                $count += $bonus;
            }
        }

        $promo = null;
        if ($promoCode !== null && trim($promoCode) !== '') {
            [$promo, $problem] = $this->promo(strtoupper(trim($promoCode)), $user, $subtotal, $singlesSubtotal);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        $total = $subtotal - ($promo['discountPence'] ?? 0);

        if (! Settings::salesOpen()) {
            $problems[] = PlayCheck::MESSAGES['sales_closed'];
        }
        if ($user && ($block = $this->check->block($user))) {
            $problems[] = PlayCheck::MESSAGES[$block];
        }
        if ($out === [] && $problems === []) {
            $problems[] = 'Your basket is empty.';
        }

        $walletCan = $user ? $this->wallet->spendable($user, $total) : 0;
        $perCompetition = [];
        foreach ($out as $l) {
            $perCompetition[$l['competitionId']] = ($perCompetition[$l['competitionId']] ?? 0) + (int) ($l['linePence'] ?? 0);
        }
        $loyaltyPoints = $total > 0 ? app(Loyalty::class)->estimate($user, $perCompetition, $total) : [];

        return [
            'lines' => $out,
            'totalPence' => $total,
            'subtotalPence' => $subtotal,
            'ticketCount' => $count,
            'promo' => $promo,
            'loyaltyPoints' => $loyaltyPoints,
            'walletCanPayPence' => $walletCan,
            'walletCovers' => $user !== null && $total > 0 && $walletCan >= $total,
            'problems' => array_values(array_unique($problems)),
            'competitions' => $competitions,
        ];
    }

    /** One priced line. No count of what's left goes to the browser. */
    private function line(Competition $c, int $tickets, int $linePence, ?string $problem, array $bundle): array
    {
        return [
            'competitionId' => $c->id, 'slug' => $c->slug, 'title' => $c->title, ...$c->pictureFields(), 'type' => $c->type,
            'quantity' => $tickets, ...$bundle, 'bonusQuantity' => 0, 'source' => null, 'vaultCaseId' => null, 'unitPence' => $c->price_pence, 'linePence' => $linePence,
            'minQuantity' => (int) ($c->min_tickets ?: 1), 'problem' => $problem, 'notice' => null,
            // SOW 2.2: the question to answer before paying (never the answer).
            'skillQuestion' => $c->hasSkillQuestion() ? ['question' => $c->skill_question, 'options' => array_values($c->skill_options)] : null,
        ];
    }

    /**
     * The next bundle up from what's on a line: the smallest bundle that gives more tickets and needs at
     * least as many paid ones. For the basket's "Upgrade to …" and the picker's nudge.
     *
     * @param  list<array{tier: BundleTier, paid: int, free: int, total: int, label: string, featured: bool}>  $offers
     */
    private function upgrade(Competition $c, array $offers, int $paidNow, int $ticketsNow): ?array
    {
        $next = collect($offers)->filter(fn ($o) => $o['paid'] >= $paidNow && $o['total'] > $ticketsNow)->sortBy('total')->first();

        return $next ? ['bundleId' => $next['tier']->id, 'label' => $next['label'], 'tickets' => $next['total'], 'freeQuantity' => $next['free'],
            'extraPence' => ($next['paid'] - $paidNow) * $c->price_pence] : null;
    }

    /**
     * The free bonus tickets a Vault Case adds to one competition: only while the basket still holds what
     * the box packed there (the same bundle, at least its single tickets), capped by what's left and by
     * the competition's biggest-bundle share.
     */
    private function boxBonus(?VaultCase $box, Competition $c, ?int $bundleId, int $singles, int $room): int
    {
        $l = $box?->lineFor($c->id);
        if (! $l || (int) ($l['bundle_tier_id'] ?? 0) !== (int) ($bundleId ?? 0) || $singles < (int) $l['singles']) {
            return 0;
        }
        $tickets = (int) $l['paid'] + (int) $l['free'];

        return max(0, min((int) $l['bonus'], $room, max(0, Bundles::shareLimit($c) - $tickets)));
    }

    /**
     * A promo code checked against the basket (SOW 2.3). Returns [promo|null, problem|null].
     *
     * @return array{0: array{code:string, discountPence:int, label:string}|null, 1: string|null}
     */
    private function promo(string $codeText, ?User $user, int $subtotal, int $singlesSubtotal): array
    {
        $code = PromoCode::query()->where('code', $codeText)->where('active', true)->first();
        if (! $code || ($code->starts_at && $code->starts_at->isFuture()) || ($code->expires_at && $code->expires_at->isPast())
            || ($code->max_uses !== null && $code->uses >= $code->max_uses)) {
            return [null, "That promo code isn't valid."];
        }
        if ($user && $code->max_uses_per_player !== null && $code->usesBy($user) >= $code->max_uses_per_player) {
            return [null, 'You’ve already used that promo code.'];
        }
        if ($code->min_spend_pence > 0 && $subtotal < $code->min_spend_pence) {
            return [null, 'Spend £'.number_format($code->min_spend_pence / 100, 2).' or more to use that promo code.'];
        }
        // Single tickets only unless the code includes bundles (bundles are already discounted).
        $eligible = $code->include_bundles ? $subtotal : $singlesSubtotal;
        $discount = $code->discountOn($eligible);
        if ($discount === 0 && $subtotal > 0) {
            return [null, $code->include_bundles ? "That promo code isn't valid." : 'Promo codes work on single tickets, not bundles.'];
        }

        return [['code' => $code->code, 'discountPence' => $discount, 'label' => $code->label()], null];
    }
}
