<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyReward;
use App\Models\Voucher;
use App\Services\Loyalty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The player's loyalty points (SOW 2.11): balances, tiers, history, rewards, and spending points. */
class LoyaltyController extends Controller
{
    public function mine(Request $r, Loyalty $loyalty): JsonResponse
    {
        $u = $r->user();

        return response()->json($loyalty->summary($u) + [
            'vouchers' => Voucher::query()->with('competition:id,title,slug', 'forCompetition:id,title,slug')->where('user_id', $u->id)
                ->where('source', 'loyalty')->latest()->limit(50)->get()
                ->map(fn (Voucher $v) => ['id' => $v->id, 'tickets' => $v->tickets, 'status' => $v->status, 'expiresAt' => $v->expires_at->toIso8601ZuluString(),
                    'forCompetition' => $v->forCompetition ? ['title' => $v->forCompetition->title, 'slug' => $v->forCompetition->slug] : null,
                    'competition' => $v->competition ? ['title' => $v->competition->title, 'slug' => $v->competition->slug] : null]),
        ]);
    }

    public function redeem(Request $r, Loyalty $loyalty): JsonResponse
    {
        $d = $r->validate(['rewardId' => ['required', 'integer']]);
        $reward = LoyaltyReward::query()->findOrFail($d['rewardId']);
        $red = $loyalty->redeem($r->user(), $reward);

        return response()->json(['redemption' => ['id' => $red->id, 'status' => $red->status, 'voucherId' => $red->voucher_id]], 201);
    }
}
