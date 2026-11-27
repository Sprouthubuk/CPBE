<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Stands in for Cashflows in tests and on a developer's machine. Never used in production
 * (AppServiceProvider refuses it). The fake payment page is /api/fake-pay/{reference}.
 */
final class FakeGateway implements PaymentGateway
{
    /** Tests: the next recurring charges answer this way ("paid", "failed", or null for no answer). */
    public static ?string $recurringResult = 'paid';

    public function createPaymentJob(Order $order, string $email, array $returnUrls, ?int $recurringDays = null, bool $debitOnly = false): array
    {
        $ref = 'FAKE-'.strtoupper(bin2hex(random_bytes(6)));
        $this->put($ref, ['number' => $order->number, 'amount' => $order->card_pence, 'status' => 'pending', 'cardType' => 'debit', 'return' => $returnUrls,
            'recurringParent' => $recurringDays !== null, 'debitOnly' => $debitOnly]);

        return ['reference' => $ref, 'redirectUrl' => URL::to('/api/fake-pay/'.$ref)];
    }

    public function chargeRecurring(string $parentReference, Order $order, int $recurringDays): ?GatewayAnswer
    {
        $parent = Cache::get('fakepay:'.$parentReference);
        if (self::$recurringResult === null) {
            return null;
        }
        if (! $parent || empty($parent['recurringParent']) || $parent['status'] !== 'paid') {
            return new GatewayAnswer(null, $order->number, null, 'GBP', 'failed');
        }
        $ref = 'FAKE-R'.strtoupper(bin2hex(random_bytes(5)));
        $this->put($ref, ['number' => $order->number, 'amount' => $order->card_pence, 'status' => self::$recurringResult, 'cardType' => 'debit', 'return' => []]);

        return $this->lookup($ref);
    }

    public function lookup(string $jobReference): ?GatewayAnswer
    {
        $j = Cache::get('fakepay:'.$jobReference);
        if (! $j) {
            return null;
        }

        return new GatewayAnswer($jobReference, $j['number'], $j['amount'], 'GBP', $j['status'], $j['status'] === 'paid' ? $j['cardType'] : null,
            $j['status'] === 'paid' ? ($j['cardToken'] ?? null) : null);
    }

    /** Test helper: what the player did on the payment page. */
    public function settle(string $jobReference, string $status, string $cardType = 'debit', ?int $amount = null, ?string $cardToken = null): void
    {
        $j = Cache::get('fakepay:'.$jobReference) ?? throw new \RuntimeException('No such fake payment job');
        $j['status'] = $status;
        $j['cardType'] = $cardType;
        $j['cardToken'] = $cardToken;
        if ($amount !== null) {
            $j['amount'] = $amount;
        }
        $this->put($jobReference, $j);
    }

    public function job(string $jobReference): ?array
    {
        return Cache::get('fakepay:'.$jobReference);
    }

    private function put(string $ref, array $j): void
    {
        // Kept a year: recurring parents are charged months later (tests move the clock).
        Cache::put('fakepay:'.$ref, $j, now()->addYear());
    }
}
