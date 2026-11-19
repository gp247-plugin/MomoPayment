<?php

namespace App\GP247\Plugins\MomoPayment\Services;

use GP247\Shop\Payment\Contracts\PaymentGateway;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use GP247\Shop\Payment\Support\CollectResult;
use GP247\Shop\Payment\Support\RefundResult;
use Illuminate\Support\Facades\Cache;

/**
 * MoMo as a gateway of the shop's payment requests: a MoMo page for what is still due
 * (collect) and a refund of a MoMo transaction (refund) — including a payment taken at
 * checkout, which the shop offers as a refund source.
 *
 * Every call uses the MoMo account of the store that OWNS the request — never the session's
 * store (NFR-SEC-momo-payment-request-store-credentials).
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-payment-request
 */
class MomoPaymentRequestGateway implements PaymentGateway
{
    public const KEY = 'MomoPayment';

    /** How long an opened MoMo page is reused for the same request and amount. */
    private const PAGE_TTL_MINUTES = 90;

    public function key(): string
    {
        return self::KEY;
    }

    public function capabilities(): array
    {
        return [self::CAP_COLLECT, self::CAP_REFUND];
    }

    /**
     * Usable when the plugin is on for the request's store, the request is in VND and that
     * store has its three keys for the mode it is in.
     *
     * @param PaymentRequest $request
     * @return bool
     */
    public function availableFor(PaymentRequest $request): bool
    {
        if (function_exists('gp247_plugin_store_enabled') && !gp247_plugin_store_enabled(self::KEY, $request->store_id)) {
            return false;
        }

        return strcasecmp((string) $request->currency, MomoFulfillment::CURRENCY) === 0
            && momopayment_configured($request->store_id);
    }

    /**
     * Open a MoMo page for what is still due on the request.
     *
     * WHY the cache keyed by amount: a double click must not open two pages (the payer could
     * pay both), but after a partial payment a page for the new amount is needed.
     *
     * @param PaymentRequest $request
     * @return CollectResult
     * @throws MomoApiException
     */
    public function collect(PaymentRequest $request): CollectResult
    {
        $store = (string) $request->store_id;
        $amount = (int) round((float) $request->outstanding());
        $cacheKey = 'momopayment:payreq:' . $request->id . ':' . $amount;
        $open = Cache::get($cacheKey);
        if (is_array($open)) {
            return new CollectResult((string) $open['payUrl'], (string) $open['orderId']);
        }

        $momoOrderId = momopayment_order_prefix($store) . '-R' . $request->id . '-' . now()->format('ymdHis');
        $label = trim((string) $request->description) !== ''
            ? mb_substr((string) $request->description, 0, 200)
            : $this->lang('payreq_line', ['id' => (string) $request->id]);

        $created = MomoApi::forStore($store)->create([
            'orderId' => $momoOrderId,
            'amount' => $amount,
            'orderInfo' => $label,
            'redirectUrl' => momopayment_payment_request_return_url(),
            'ipnUrl' => momopayment_ipn_url(),
            'extraData' => MomoExtraData::encode(MomoExtraData::REQUEST, (string) $request->id, $store),
            'requestType' => 'payWithMethod',
            'lang' => 'vi',
        ]);
        if ((int) $created['resultCode'] !== 0 || empty($created['payUrl'])) {
            throw new MomoApiException('MoMo refused to open the payment page: ' . ($created['message'] ?? ''), (string) $created['resultCode']);
        }

        Cache::put($cacheKey, ['orderId' => $momoOrderId, 'payUrl' => (string) $created['payUrl']], now()->addMinutes(self::PAGE_TTL_MINUTES));

        return new CollectResult((string) $created['payUrl'], $momoOrderId);
    }

    /**
     * Refund part or all of a MoMo transaction.
     *
     * WHY the refund orderId comes from the idempotency key: MoMo refuses a second refund with
     * the same orderId, so the same click sent twice can never give the money back twice,
     * while each legitimate refund (the key carries the count of earlier refunds) gets its own.
     *
     * @param PaymentRequest  $request
     * @param PaymentMovement $original       Collection being refunded; gateway_ref = MoMo transId.
     * @param float           $amount         VND.
     * @param string|null     $idempotencyKey Issued by the shop for a refund source.
     * @return RefundResult
     * @throws MomoApiException
     */
    public function refund(PaymentRequest $request, PaymentMovement $original, float $amount, ?string $idempotencyKey = null): RefundResult
    {
        $store = (string) $request->store_id;
        $transId = (string) $original->gateway_ref;
        if (!ctype_digit($transId)) {
            throw new MomoApiException('This payment has no MoMo transaction id to refund', 'no_trans_id');
        }
        $minor = (int) round($amount);
        $idempotencyKey ??= 'gp247-payreq-refund-' . $original->id . '-'
            . app(PaymentRequestService::class)->refundsOf($original)->count() . '-' . $minor;

        $result = MomoApi::forStore($store)->refund([
            'orderId' => momopayment_order_prefix($store) . '-RF' . substr(hash('sha256', $idempotencyKey), 0, 20),
            'amount' => $minor,
            'transId' => (int) $transId,
            'description' => $this->lang('refund_description', ['id' => (string) $request->id]),
        ]);

        $refundId = (string) ($result['transId'] ?? '');
        if ((int) $result['resultCode'] !== 0 || $refundId === '' || $refundId === '0') {
            throw new MomoApiException('MoMo refused the refund: ' . ($result['message'] ?? ''), (string) $result['resultCode']);
        }

        return new RefundResult($refundId, true);
    }

    /**
     * Order status of a checkout order (REST API) once paid in full / fully refunded: the
     * statuses this plugin's website payment step sets for that store (declared without
     * `implements` so the plugin still loads on a shop that predates the contract).
     *
     * @param string $event   'paid' | 'refunded'.
     * @param string $storeId Store of the payment request.
     * @return int|null
     */
    public function orderStatusAfter(string $event, string $storeId): ?int
    {
        $key = match ($event) {
            'paid' => 'order_status_success',
            'refunded' => 'order_status_refunded',
            default => null,
        };
        $status = $key === null ? 0 : (int) momopayment_config($key, 0, $storeId);

        return $status > 0 ? $status : null;
    }

    private function lang(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/MomoPayment::lang.' . $key, $replace);
    }
}
