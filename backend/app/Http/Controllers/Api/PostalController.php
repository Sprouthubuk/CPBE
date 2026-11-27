<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\PostalReference;
use App\Services\Postal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Amendment 7: postal entry references for players, and the postal entry provider's submissions. */
class PostalController extends Controller
{
    /** The signed-in player's reference for one competition, made the first time they ask. */
    public function reference(Request $r): JsonResponse
    {
        $d = $r->validate(['competitionId' => ['required', 'string', 'max:40']]);
        $c = Competition::query()->published()->where('id', $d['competitionId'])->where('closes_at', '>', now())->firstOrFail();
        $ref = PostalReference::for($r->user(), $c);

        return response()->json(['reference' => PostalReference::pretty($ref->code), 'competitionId' => $c->id, 'title' => $c->title,
            'closesAt' => $c->closes_at->toIso8601ZuluString()]);
    }

    /**
     * The provider submits envelopes: `{ entries: [{ id, reference, receivedAt }] }` with `Authorization: Bearer <token>`.
     * Each is matched and entered exactly like one an admin logs; a repeated `id` enters once.
     */
    public function provider(Request $r, Postal $postal): JsonResponse
    {
        $token = (string) config('services.postal.provider_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $r->bearerToken()), 401);
        $d = $r->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.id' => ['required', 'string', 'max:90'],
            'entries.*.reference' => ['required', 'string', 'max:30'],
            'entries.*.receivedAt' => ['required', 'date'],
        ]);
        $results = [];
        foreach ($d['entries'] as $e) {
            $res = $postal->logReference($e['reference'], \Illuminate\Support\Carbon::parse($e['receivedAt'])->utc(), null, 'provider:'.$e['id']);
            $results[] = ['id' => $e['id'], 'status' => $res['status'], 'reason' => $res['reason'], 'duplicate' => $res['duplicate'] ?? false];
        }

        return response()->json(['results' => $results]);
    }
}
