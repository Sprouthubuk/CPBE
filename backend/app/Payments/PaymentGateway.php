<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * Create a hosted payment for the order's card part. Returns the job reference to store on the
     * order and the address to send the player to.
     *
     * With $recurringDays it is also the parent of later renewals (memberships, SOW 2.12): the player
     * pays the first time with 3-D Secure and later payments are charged with chargeRecurring().
     *
     * @return array{reference:string, redirectUrl:string}
     */
    public function createPaymentJob(Order $order, string $email, array $returnUrls, ?int $recurringDays = null): array;

    /**
     * Charge a renewal against a parent job, without the player (Cashflows has no schedule of its own:
     * we start each one). Null when the gateway didn't answer; otherwise its answer for the new job.
     */
    public function chargeRecurring(string $parentReference, Order $order, int $recurringDays): ?GatewayAnswer;

    /** Ask the gateway what happened to a payment job. Null when it didn't answer. */
    public function lookup(string $jobReference): ?GatewayAnswer;
}
