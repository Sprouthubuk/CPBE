<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Amendment 2: events sent to the Client's own tools. Each event is queued per subscribed endpoint once the change
 * is saved, then sent by `platform:webhooks` (every minute) as JSON `{ id, event, createdAt, data }`, signed with
 * `X-Signature: sha256=<HMAC-SHA256 of the body with the endpoint's secret>`. Failures retry with growing gaps,
 * up to eight tries over about a day.
 */
final class Webhooks
{
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 180, 360, 720]; // the gaps after tries 1–7; the eighth failure gives up

    public static function fire(string $event, array $data): void
    {
        DB::afterCommit(function () use ($event, $data) {
            foreach (WebhookEndpoint::query()->where('active', true)->get() as $e) {
                if (in_array($event, (array) $e->events, true)) {
                    WebhookDelivery::query()->create(['webhook_endpoint_id' => $e->id, 'event' => $event, 'payload' => $data, 'next_attempt_at' => now()]);
                }
            }
        });
    }

    /** Send what's due. Returns [sent, failed]. */
    public function send(int $limit = 200): array
    {
        $sent = $failed = 0;
        foreach (WebhookDelivery::query()->with('endpoint')->whereNull('delivered_at')->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get() as $d) {
            $body = json_encode(['id' => 'whd_'.$d->id, 'event' => $d->event, 'createdAt' => $d->created_at->toIso8601ZuluString(), 'data' => $d->payload],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $status = 0;
            try {
                $status = Http::timeout(10)->withHeaders([
                    'Content-Type' => 'application/json', 'X-Event' => $d->event,
                    'X-Signature' => 'sha256='.hash_hmac('sha256', $body, $d->endpoint->secret),
                ])->withBody($body, 'application/json')->post($d->endpoint->url)->status();
            } catch (\Throwable) {
            }
            $attempts = $d->attempts + 1;
            if ($status >= 200 && $status < 300) {
                $d->forceFill(['attempts' => $attempts, 'delivered_at' => now(), 'last_status' => $status, 'next_attempt_at' => null])->save();
                $sent++;
            } else {
                $wait = self::BACKOFF_MINUTES[$attempts - 1] ?? null;
                $d->forceFill(['attempts' => $attempts, 'last_status' => $status ?: null, 'next_attempt_at' => $wait ? now()->addMinutes($wait) : null])->save();
                $failed++;
            }
        }

        return [$sent, $failed];
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
