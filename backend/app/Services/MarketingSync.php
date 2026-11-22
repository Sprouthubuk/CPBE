<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The email and SMS marketing platforms (SOW 3.3). One email platform and one SMS platform, chosen by the
 * Client; keys live only in the server's environment (Forge), never in the database:
 *
 *   MARKETING_EMAIL_DRIVER = none | mailchimp | klaviyo | webhook
 *   MARKETING_EMAIL_KEY, MARKETING_EMAIL_LIST (Mailchimp audience id), MARKETING_WEBHOOK_URL, MARKETING_WEBHOOK_SECRET
 *   SMS_MARKETING_DRIVER = none | webhook (its platform's opt-outs come back through /api/webhooks/sms-opt-out)
 *
 * What's sent: each player's consent and details when they join or change them, purchases (for segments and
 * flows), abandoned checkouts, and segment tags nightly (App\Services\Segments). Only marketing-consented
 * players are subscribed; withdrawing consent unsubscribes. A platform that's down never stops the site.
 */
class MarketingSync
{
    public function enabled(): bool
    {
        return in_array(config('services.marketing.email_driver'), ['mailchimp', 'klaviyo', 'webhook'], true)
            || config('services.marketing.sms_driver') === 'webhook';
    }

    /** Create or update the player on the platforms, with their current consent. */
    public function contact(User $u, array $tags = []): void
    {
        $this->safely(function () use ($u, $tags) {
            match (config('services.marketing.email_driver')) {
                'mailchimp' => $this->mailchimpMember($u, $tags),
                'klaviyo' => $this->klaviyoProfile($u, $tags),
                'webhook' => $this->webhook('contact', $this->contactData($u) + ['tags' => $tags]),
                default => null,
            };
            if (config('services.marketing.sms_driver') === 'webhook' && $u->phone) {
                $this->webhook('sms_contact', ['email' => $u->email, 'phone' => $u->phone, 'smsConsent' => (bool) $u->marketing_sms], 'sms');
            }
        });
    }

    /** A purchase, for segments and flows (real money only). */
    public function purchase(Order $o): void
    {
        $value = ((int) $o->card_pence + (int) $o->paid_deposit_pence + (int) $o->paid_winnings_pence) / 100;
        $this->event($o->user, 'Placed Order', ['order' => $o->number, 'value' => $value, 'tickets' => (int) $o->lines()->sum('quantity')]);
    }

    /** Any other event (Abandoned Checkout, …). Only for players who opted in. */
    public function event(User $u, string $name, array $props): void
    {
        if (! $u->marketing_email && ! $u->marketing_sms) {
            return;
        }
        $this->safely(fn () => match (config('services.marketing.email_driver')) {
            'klaviyo' => $this->klaviyo('events/', ['data' => ['type' => 'event', 'attributes' => [
                'properties' => $props, 'value' => $props['value'] ?? null, 'unique_id' => ($props['order'] ?? null) ? $name.':'.$props['order'] : null,
                'metric' => ['data' => ['type' => 'metric', 'attributes' => ['name' => $name]]],
                'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $u->email]]],
            ]]]),
            'mailchimp' => $this->mailchimp('post', 'lists/'.config('services.marketing.email_list').'/members/'.md5(strtolower($u->email)).'/events',
                ['name' => str_replace(' ', '_', strtolower($name)), 'properties' => array_map('strval', $props)]),
            'webhook' => $this->webhook('event', ['email' => $u->email, 'name' => $name, 'properties' => $props]),
            default => null,
        });
    }

    private function contactData(User $u): array
    {
        return ['email' => $u->email, 'firstName' => $u->first_name, 'lastName' => $u->last_name, 'phone' => $u->marketing_sms ? $u->phone : null,
            'emailConsent' => (bool) $u->marketing_email, 'smsConsent' => (bool) $u->marketing_sms, 'joinedAt' => $u->created_at?->toIso8601ZuluString()];
    }

    private function mailchimpMember(User $u, array $tags): void
    {
        $list = config('services.marketing.email_list');
        $hash = md5(strtolower($u->email));
        $this->mailchimp('put', "lists/{$list}/members/{$hash}", ['email_address' => $u->email,
            'status_if_new' => $u->marketing_email ? 'subscribed' : 'unsubscribed', 'status' => $u->marketing_email ? 'subscribed' : 'unsubscribed',
            'merge_fields' => ['FNAME' => (string) $u->first_name, 'LNAME' => (string) $u->last_name]]);
        if ($tags) {
            $this->mailchimp('post', "lists/{$list}/members/{$hash}/tags", ['tags' => array_map(fn ($t) => ['name' => $t, 'status' => 'active'], $tags)]);
        }
    }

    private function mailchimp(string $method, string $path, array $body): void
    {
        $key = (string) config('services.marketing.email_key');
        $dc = substr($key, (int) strrpos($key, '-') + 1) ?: 'us1';
        Http::timeout(10)->withBasicAuth('platform', $key)->$method("https://{$dc}.api.mailchimp.com/3.0/{$path}", $body)->throw();
    }

    private function klaviyoProfile(User $u, array $tags): void
    {
        $this->klaviyo('profile-import/', ['data' => ['type' => 'profile', 'attributes' => ['email' => $u->email, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'phone_number' => $u->marketing_sms ? $u->phone : null, 'properties' => ['segments' => $tags, 'email_consent' => (bool) $u->marketing_email, 'sms_consent' => (bool) $u->marketing_sms]]]]);
    }

    private function klaviyo(string $path, array $body): void
    {
        Http::timeout(10)->withHeaders(['Authorization' => 'Klaviyo-API-Key '.config('services.marketing.email_key'), 'revision' => '2024-10-15', 'accept' => 'application/json'])
            ->post('https://a.klaviyo.com/api/'.$path, $body)->throw();
    }

    /** A signed JSON post to the Client's own hook (Zapier, Make, or the platform's webhook). */
    private function webhook(string $type, array $data, string $which = 'email'): void
    {
        $url = (string) config($which === 'sms' ? 'services.marketing.sms_webhook_url' : 'services.marketing.webhook_url');
        if (! str_starts_with($url, 'https://')) {
            return;
        }
        $body = json_encode(['type' => $type, 'data' => $data, 'sentAt' => now()->toIso8601ZuluString()], JSON_UNESCAPED_SLASHES);
        Http::timeout(10)->withHeaders(['X-Signature' => hash_hmac('sha256', $body, (string) config('services.marketing.webhook_secret'))])
            ->withBody($body, 'application/json')->post($url)->throw();
    }

    private function safely(callable $fn): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Marketing platform sync failed', ['error' => class_basename($e)]); // never the key or the player's data
        }
    }
}
