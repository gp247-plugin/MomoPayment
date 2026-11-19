<?php

namespace App\GP247\Plugins\MomoPayment\Services;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a payment result MoMo confirmed into ledger entries — the one place that does it.
 *
 * WHY one class for the return page and the IPN: MoMo usually delivers the IPN while the
 * shopper is being redirected, so both paths run side by side for the same payment, and MoMo
 * resends IPNs. The ledgers are unique on MoMo's transId, which makes the money idempotent;
 * this class makes the side effects (status, history) follow only from that
 * (ADR momo-payment_idempotent-fulfillment, NFR-SEC-payment-idempotency).
 *
 * Input is always a result MoMo confirmed — a signature-checked IPN or the answer of the query
 * API — never the query string of the return URL. Never calls MoMo itself.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-ipn-fulfillment
 * @aidlc-story US-momo-payment-payment-request
 * @aidlc-adr momo-payment_idempotent-fulfillment
 */
class MomoFulfillment
{
    public const METHOD = 'MomoPayment';

    /** MoMo only takes Vietnamese dong. */
    public const CURRENCY = 'VND';

    /** resultCode of a successful (captured) payment. */
    public const PAID = 0;

    /**
     * Record the payment of an order. Safe to call any number of times, concurrently.
     *
     * @param ShopOrder            $order
     * @param array<string, mixed> $result MoMo-confirmed result with resultCode 0.
     * @return bool True only for the call that wrote the ledger row.
     */
    public function fulfillOrder(ShopOrder $order, array $result): bool
    {
        $transId = self::transId($result);
        if ((int) ($result['resultCode'] ?? -1) !== self::PAID || $transId === '') {
            return false;
        }

        $paid = (float) (int) ($result['amount'] ?? 0);
        $matches = strcasecmp((string) $order->currency, self::CURRENCY) === 0
            && (int) round((float) $order->total) === (int) $paid;
        $mismatch = 'MoMo collected ' . $paid . ' ' . self::CURRENCY . ' but the order is ' . $order->total . ' ' . $order->currency;

        // Record what MoMo actually took, never the order total "to make it match".
        $row = self::ignoreDuplicate(fn () => $order->recordPayment($paid, self::METHOD, $transId, null, $matches ? null : $mismatch));
        $isNew = $row !== null && $row->wasRecentlyCreated;
        $order = ShopOrder::find($order->id);
        $content = 'MoMo payment ' . $transId . ' (' . ($result['orderId'] ?? '?') . ')';

        if (!$matches) {
            if ($isNew) {
                gp247_report('MomoPayment: order ' . $order->id . ': ' . $mismatch);
                $this->history($order, $content . ': ' . $mismatch);
            }

            return $isNew;
        }

        // WHY not stop on a replay: a delivery that recorded the money and then failed before
        // the status change is retried by MoMo — the retry must still move a NEW order on.
        // Only a NEW order moves: a late IPN must not undo what the admin already did.
        if ((int) $order->status === ShopOrderStatus::NEW) {
            $target = (int) momopayment_config('order_status_success', ShopOrderStatus::PROCESSING, $order->store_id) ?: ShopOrderStatus::PROCESSING;
            $refused = $order->changeStatus($target, ['content' => $content, 'customer_id' => $order->customer_id ?: 0]);
            if ($refused !== null) {
                gp247_report('MomoPayment: order ' . $order->id . ' status not changed: ' . $refused);
            }
        } elseif ($isNew) {
            $this->history($order, $content);
        }

        return $isNew;
    }

    /**
     * A payment MoMo reports as failed or cancelled: written on the order, nothing else — the
     * shopper may still pay again, or the admin decides.
     *
     * @param ShopOrder            $order
     * @param array<string, mixed> $result
     * @return void
     */
    public function recordFailure(ShopOrder $order, array $result): void
    {
        $this->history($order, 'MoMo payment not completed (' . ($result['orderId'] ?? '?') . '): '
            . (int) ($result['resultCode'] ?? -1) . ' ' . mb_substr((string) ($result['message'] ?? ''), 0, 200));
    }

    /**
     * Record the money of a payment request (pay link).
     *
     * @param PaymentRequest       $request
     * @param array<string, mixed> $result MoMo-confirmed result.
     * @return PaymentMovement|null The collect movement (new or already recorded); null when not paid.
     */
    public function fulfillRequest(PaymentRequest $request, array $result): ?PaymentMovement
    {
        $transId = self::transId($result);
        if ((int) ($result['resultCode'] ?? -1) !== self::PAID || $transId === '') {
            return null;
        }
        $note = strcasecmp((string) $request->currency, self::CURRENCY) === 0
            ? null
            : 'MoMo collected in VND but the request is in ' . $request->currency;
        if ($note !== null) {
            gp247_report('MomoPayment: payment request ' . $request->id . ': ' . $note);
        }

        $data = [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => (float) (int) ($result['amount'] ?? 0),
            'gateway' => self::METHOD,
            'gateway_ref' => $transId,
            'method' => PaymentMovement::METHOD_GATEWAY,
            'reference' => (string) ($result['orderId'] ?? ''),
            'note' => $note,
            'confirmed' => true,
        ];
        $service = app(PaymentRequestService::class);

        return self::ignoreDuplicate(fn () => $service->recordMovement($request, $data))
            ?? $service->findGatewayCollect(self::METHOD, $transId);
    }

    /**
     * Run a ledger write, treating a unique-index collision as "already recorded" (two
     * deliveries at the same instant can both pass the ledger's own check).
     *
     * @template T
     * @param callable():T $write
     * @return T|null
     */
    public static function ignoreDuplicate(callable $write)
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return string MoMo's transaction id, '' when MoMo has none yet (0).
     */
    private static function transId(array $result): string
    {
        $id = (string) ($result['transId'] ?? '');

        return ctype_digit($id) && $id !== '0' ? $id : '';
    }

    private function history(ShopOrder $order, string $content): void
    {
        $order->addOrderHistory([
            'order_id' => $order->id,
            'content' => $content,
            'customer_id' => $order->customer_id ?: 0,
            'order_status_id' => $order->status,
        ]);
    }
}
