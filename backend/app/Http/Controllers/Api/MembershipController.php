<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Services\Memberships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Memberships (SOW 2.12): see tiers, join, upgrade, downgrade, cancel, keep, and pay a failed renewal. */
class MembershipController extends Controller
{
    public function __construct(private Memberships $memberships) {}

    public function mine(Request $r): JsonResponse
    {
        return response()->json($this->memberships->summary($r->user()));
    }

    public function join(Request $r): JsonResponse
    {
        $d = $r->validate(['tierId' => ['required', 'integer'], 'payWith' => ['required', 'in:card,wallet']]);

        return $this->paid($this->memberships->join($r->user(), MembershipTier::query()->findOrFail($d['tierId']), $d['payWith']), $r, 201);
    }

    public function change(Request $r): JsonResponse
    {
        $d = $r->validate(['tierId' => ['required', 'integer']]);
        $tier = MembershipTier::query()->findOrFail($d['tierId']);
        $current = $this->memberships->current($r->user());
        if ($current && $tier->price_pence < $current->tier->price_pence) {
            $this->memberships->downgrade($r->user(), $tier);

            return response()->json($this->memberships->summary($r->user()));
        }
        $order = $this->memberships->upgrade($r->user(), $tier);

        return $order ? $this->paid($order, $r) : response()->json($this->memberships->summary($r->user()));
    }

    public function cancel(Request $r): JsonResponse
    {
        $this->memberships->cancel($r->user());

        return response()->json($this->memberships->summary($r->user()));
    }

    public function keep(Request $r): JsonResponse
    {
        $this->memberships->keep($r->user());

        return response()->json($this->memberships->summary($r->user()));
    }

    public function payNow(Request $r): JsonResponse
    {
        return $this->paid($this->memberships->payNow($r->user()), $r, 201);
    }

    /** Paid at once (wallet / stored card), or the payment page to go to. */
    private function paid(Order $order, Request $r, int $status = 200): JsonResponse
    {
        return response()->json([
            'order' => ['number' => $order->number, 'status' => $order->status],
            'redirectUrl' => $order->status === 'pending_payment' ? $order->gateway_url : null,
        ] + $this->memberships->summary($r->user()), $status);
    }
}
