<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CompetitionPresenter;
use App\Models\Competition;
use App\Models\Order;
use App\Models\User;
use App\Services\Conversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SOW 2.18: recommendations and upsells, one-click repeat purchase, A/B tests; SOW 3.3: SMS opt-outs. */
class ConversionController extends Controller
{
    public function __construct(private Conversion $conversion) {}

    /** Competitions to suggest (signed in: personalised). `exclude` is a comma-separated list of ids in the basket. */
    public function recommendations(Request $r, CompetitionPresenter $present): JsonResponse
    {
        $exclude = array_slice(array_filter(explode(',', (string) $r->query('exclude', ''))), 0, 50);
        $limit = max(1, min(8, (int) $r->query('limit', 4)));
        $user = auth('sanctum')->user();
        $list = $this->conversion->recommend($user instanceof User ? $user : null, $exclude, $limit)->map(fn (Competition $c) => $present->summary($c))->values();

        return response()->json(['data' => $list]);
    }

    /** One-click repeat purchase: an earlier order's lines that can still be bought, for the basket. */
    public function repeat(Request $r, string $number): JsonResponse
    {
        $o = Order::query()->where('number', $number)->where('user_id', $r->user()->id)->where('order_type', 'tickets')->firstOrFail();

        return response()->json($this->conversion->repeat($o, $r->query('via') === 'recovery' ? 'recovery' : 'repeat'));
    }

    /** The running A/B tests' variants for this player, or this consenting visitor (cp_vid). */
    public function experiments(Request $r): JsonResponse
    {
        $subject = $this->subject($r);

        return response()->json(['data' => $subject ? $this->conversion->experiments($subject) : (object) []]);
    }

    public function seen(Request $r, string $key): JsonResponse
    {
        if ($subject = $this->subject($r)) {
            $this->conversion->seen($subject, $key);
        }

        return response()->json(['ok' => true]);
    }

    private function subject(Request $r): ?string
    {
        $user = auth('sanctum')->user();
        if ($user instanceof User) {
            return 'u:'.$user->id;
        }
        $vid = $r->cookie('cp_vid');

        return $r->cookie('cp_consent') === 'accepted' && is_string($vid) && preg_match('/^[a-f0-9]{16,40}$/', $vid) ? 'v:'.$vid : null;
    }

    /** The SMS platform reports an opt-out (STOP): marketing texts end at once. Signed with SMS_OPTOUT_SECRET. */
    public function smsOptOut(Request $r): JsonResponse
    {
        $secret = (string) config('services.marketing.sms_optout_secret');
        abort_unless($secret !== '' && hash_equals(hash_hmac('sha256', $r->getContent(), $secret), (string) $r->header('X-Signature')), 403);
        $d = $r->validate(['phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email']]);
        $digits = fn (?string $p) => $p ? preg_replace('/\D/', '', preg_replace('/^\+?44/', '0', $p)) : null;
        $phone = $digits($d['phone'] ?? null);
        $n = 0;
        User::query()->where('marketing_sms', true)->where(function ($q) use ($d) {
            $q->when($d['email'] ?? null, fn ($w) => $w->orWhere('email', strtolower($d['email'])))->orWhereNotNull('phone');
        })->get()->filter(fn (User $u) => ($d['email'] ?? null) && strtolower($u->email) === strtolower($d['email']) || ($phone && $digits($u->phone) === $phone))
            ->each(function (User $u) use (&$n) {
                $u->forceFill(['marketing_sms' => false])->save();
                $n++;
            });

        return response()->json(['optedOut' => $n]);
    }
}
