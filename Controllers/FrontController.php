<?php
#App\GP247\Plugins\MomoPayment\Controllers\FrontController.php
namespace App\GP247\Plugins\MomoPayment\Controllers;

use App\GP247\Plugins\MomoPayment\Services\MomoApi;
use App\GP247\Plugins\MomoPayment\Services\MomoApiException;
use App\GP247\Plugins\MomoPayment\Services\MomoExtraData;
use App\GP247\Plugins\MomoPayment\Services\MomoFulfillment;
use GP247\Front\Controllers\RootFrontController;
use GP247\Shop\Controllers\ShopCartController;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * MoMo payment page (payWithMethod) for the storefront.
 *
 * processOrder() is called by the shop right after the order is created; the shopper is sent
 * to MoMo and comes back to paymentReturn(). The IPN records the money even when the shopper
 * never comes back. Both paths share MomoFulfillment.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-hosted-checkout
 * @aidlc-story US-momo-payment-ipn-fulfillment
 * @aidlc-story US-momo-payment-payment-request
 * @aidlc-adr momo-payment_pay-with-method-no-sdk
 */
class FrontController extends RootFrontController
{
    /** resultCodes of a payment MoMo is still processing — money comes with the IPN. */
    private const PENDING = [7000, 7002, 9000];

    /** resultCode of a payment the shopper has not confirmed yet. */
    private const NOT_CONFIRMED = 1000;

    /** How long an opened MoMo page is reused for the same order (MoMo keeps it ~100 minutes). */
    private const PAGE_TTL_MINUTES = 90;

    /**
     * Open the MoMo page for the order just placed and send the shopper there.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processOrder()
    {
        $orderId = session('orderID');
        $order = $orderId ? ShopOrder::find($orderId) : null;
        if ($order === null) {
            gp247_report('MomoPayment: processOrder without a valid order in session');
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_order'));
        }
        if (strcasecmp((string) $order->currency, MomoFulfillment::CURRENCY) !== 0) {
            return $this->abandon($this->lang('error_currency'));
        }

        $store = (string) $order->store_id;
        $api = MomoApi::forStore($store);
        if (!$api->configured()) {
            gp247_report('MomoPayment: keys not configured for store ' . $store);
            return $this->abandon($this->lang('error_not_configured'));
        }

        // Same order sent again while its MoMo page is open → the same page, never a second
        // one the shopper could also pay.
        $cacheKey = 'momopayment:checkout:' . $order->id;
        $open = Cache::get($cacheKey);
        if (is_array($open) && ($open['orderId'] ?? null) === (string) $order->transaction) {
            return redirect()->away((string) $open['payUrl']);
        }

        $momoOrderId = momopayment_order_prefix($store) . '-' . $order->id . '-' . $this->nextAttempt($order, $store);
        try {
            $created = $api->create([
                'orderId' => $momoOrderId,
                'amount' => (int) round((float) $order->total),
                'orderInfo' => $this->lang('order_info', ['id' => (string) $order->id]),
                'redirectUrl' => momopayment_return_url(),
                'ipnUrl' => momopayment_ipn_url(),
                'extraData' => MomoExtraData::encode(MomoExtraData::ORDER, (string) $order->id, $store),
                'requestType' => 'payWithMethod',
                'lang' => $this->momoLang(),
            ]);
        } catch (MomoApiException $e) {
            gp247_report('MomoPayment: create for order ' . $order->id . ' failed: ' . $e->momoCode() . ': ' . $e->getMessage());
            return $this->abandon($this->lang('error_create', ['reason' => '']));
        }

        if ((int) $created['resultCode'] !== 0 || empty($created['payUrl'])) {
            gp247_report('MomoPayment: create for order ' . $order->id . ' refused: ' . $created['resultCode'] . ' ' . ($created['message'] ?? ''));
            return $this->abandon($this->lang('error_create', ['reason' => mb_substr((string) ($created['message'] ?? ''), 0, 200)]));
        }

        $order->update(['transaction' => $momoOrderId]);
        Cache::put($cacheKey, ['orderId' => $momoOrderId, 'payUrl' => (string) $created['payUrl']], now()->addMinutes(self::PAGE_TTL_MINUTES));

        return redirect()->away((string) $created['payUrl']);
    }

    /**
     * Shopper is back from MoMo. Nothing in the URL is trusted: the MoMo order id must be the
     * one this shopper's order opened, and the result is read from MoMo's query API
     * (NFR-SEC-momo-return-bound-to-session).
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function paymentReturn(Request $request)
    {
        $home = redirect(gp247_route_front('front.home'));
        $orderId = (string) session('orderID', '');
        $momoOrderId = (string) $request->query('orderId', '');
        $order = $orderId !== '' ? ShopOrder::find($orderId) : null;
        if ($order === null || $momoOrderId === '' || $momoOrderId !== (string) $order->transaction) {
            gp247_report('MomoPayment: return for ' . ($momoOrderId ?: '?') . ' does not match the order in session ' . ($orderId ?: '?'));
            return $home->with('error', $this->lang('error_verify'));
        }

        try {
            $result = MomoApi::forStore((string) $order->store_id)->query($momoOrderId);
        } catch (MomoApiException $e) {
            gp247_report('MomoPayment: query ' . $momoOrderId . ' failed: ' . $e->momoCode() . ': ' . $e->getMessage());
            return $home->with('error', $this->lang('error_verify'));
        }

        $extra = MomoExtraData::decode($result['extraData'] ?? null);
        if (($result['orderId'] ?? null) !== $momoOrderId || $extra === null
            || $extra['k'] !== MomoExtraData::ORDER || $extra['id'] !== (string) $order->id) {
            gp247_report('MomoPayment: query of ' . $momoOrderId . ' names another order');
            return $home->with('error', $this->lang('error_verify'));
        }

        $code = (int) $result['resultCode'];
        if ($code === MomoFulfillment::PAID) {
            (new MomoFulfillment())->fulfillOrder($order, $result);

            return $this->cartController()->completeOrder();
        }
        if (in_array($code, self::PENDING, true)) {
            return $this->cartController()->completeOrder();
        }
        if ($code === self::NOT_CONFIRMED) {
            return redirect(gp247_route_front('cart'))->with('error', $this->lang('error_not_paid'));
        }

        return $this->failedOnReturn(ShopOrder::find($order->id), $result);
    }

    /**
     * MoMo's IPN. Answers 400 to anything whose signature does not match the account of the
     * store it names, 204 once handled (or deliberately ignored), 500 only on an unexpected
     * error so MoMo retries (NFR-SEC-momo-ipn-signature).
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function ipn(Request $request)
    {
        $result = json_decode((string) $request->getContent(), true);
        $extra = is_array($result) ? MomoExtraData::decode($result['extraData'] ?? null) : null;
        if ($extra === null || !MomoApi::forStore($extra['store'])->verifyResult($result)) {
            return response('Invalid signature', 400);
        }

        try {
            if ($extra['k'] === MomoExtraData::ORDER) {
                $this->ipnForOrder($extra, $result);
            } else {
                $this->ipnForRequest($extra, $result);
            }
        } catch (\Throwable $e) {
            gp247_report('MomoPayment: IPN ' . ($result['orderId'] ?? '?') . ' failed: ' . $e->getMessage());
            return response('Error', 500);
        }

        return response()->noContent();
    }

    /**
     * Payer is back from paying a payment request. The result is read from MoMo with the keys
     * of the request it was opened for (a store this domain serves — a marketplace store
     * without a domain is served by ROOT), recorded idempotently (the IPN usually got there
     * first), and the payer goes back to the link's page.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function paymentRequestReturn(Request $request)
    {
        $home = redirect(gp247_route_front('front.home'));
        $momoOrderId = (string) $request->query('orderId', '');
        $storeId = (string) config('app.storeId', GP247_STORE_ID_ROOT);
        if ($momoOrderId === '' || !class_exists(PaymentRequestService::class)) {
            return $home->with('error', $this->lang('payreq_error'));
        }

        // WHY look the request up first: its own store's keys opened the page (a vendor store
        // may have its own MoMo account); an unknown page falls back to this domain's keys.
        $known = PaymentRequest::where('gateway', MomoFulfillment::METHOD)->where('gateway_ref', $momoOrderId)->first();
        $keyStore = $known !== null && $this->servedHere($known, $storeId) ? (string) $known->store_id : $storeId;

        try {
            $result = MomoApi::forStore($keyStore)->query($momoOrderId);
        } catch (MomoApiException $e) {
            gp247_report('MomoPayment: query payment request ' . $momoOrderId . ' failed: ' . $e->momoCode() . ': ' . $e->getMessage());
            return $home->with('error', $this->lang('payreq_error'));
        }

        $extra = MomoExtraData::decode($result['extraData'] ?? null);
        $paymentRequest = $extra !== null && $extra['k'] === MomoExtraData::REQUEST ? PaymentRequest::find($extra['id']) : null;
        if ($paymentRequest === null || ($result['orderId'] ?? null) !== $momoOrderId || !$this->servedHere($paymentRequest, $storeId)) {
            gp247_report('MomoPayment: return for ' . $momoOrderId . ' does not match a payment request of store ' . $storeId);
            return $home->with('error', $this->lang('payreq_error'));
        }

        $back = app(PaymentRequestService::class)->publicUrl($paymentRequest);
        $target = $back !== null ? redirect()->away($back) : $home;
        $code = (int) $result['resultCode'];
        if (in_array($code, self::PENDING, true)) {
            return $target->with('success', $this->lang('payreq_processing'));
        }
        if ($code !== MomoFulfillment::PAID) {
            return $target->with('error', $this->lang('error_not_paid'));
        }

        try {
            (new MomoFulfillment())->fulfillRequest($paymentRequest, $result);
        } catch (\Throwable $e) {
            gp247_report('MomoPayment: recording payment request ' . $paymentRequest->id . ' on return failed: ' . $e->getMessage());
            return $target->with('error', $this->lang('payreq_error'));
        }

        return $target->with('success', $this->lang('payreq_paid'));
    }

    /**
     * @param array{k: string, id: string, store: string} $extra
     * @param array<string, mixed>                       $result Signature-checked IPN.
     * @return void
     */
    private function ipnForOrder(array $extra, array $result): void
    {
        $order = ShopOrder::find($extra['id']);
        // WHY the store check: the keys of the store named in extraData verified the
        // signature, so the money may only land on an order of that same store.
        if ($order === null || (string) $order->store_id !== $extra['store']) {
            gp247_report('MomoPayment: IPN ' . ($result['orderId'] ?? '?') . ' names an unknown order ' . $extra['id']);
            return;
        }
        $fulfillment = new MomoFulfillment();
        if ((int) ($result['resultCode'] ?? -1) === MomoFulfillment::PAID) {
            $fulfillment->fulfillOrder($order, $result);
        } elseif (!in_array((int) ($result['resultCode'] ?? -1), self::PENDING, true)) {
            $fulfillment->recordFailure($order, $result);
        }
    }

    /**
     * @param array{k: string, id: string, store: string} $extra
     * @param array<string, mixed>                       $result Signature-checked IPN.
     * @return void
     */
    private function ipnForRequest(array $extra, array $result): void
    {
        $paymentRequest = class_exists(PaymentRequest::class) ? PaymentRequest::find($extra['id']) : null;
        if ($paymentRequest === null || (string) $paymentRequest->store_id !== $extra['store']) {
            gp247_report('MomoPayment: IPN ' . ($result['orderId'] ?? '?') . ' names an unknown payment request ' . $extra['id']);
            return;
        }
        if ((int) ($result['resultCode'] ?? -1) === MomoFulfillment::PAID) {
            (new MomoFulfillment())->fulfillRequest($paymentRequest, $result);
        }
    }

    /**
     * MoMo says the payment failed or was cancelled.
     *
     * WHY the guards: the return URL can be opened again later. An order that has money after
     * all is finished, not undone; an order the admin has already moved on is left alone; only
     * a still-new order without money is cancelled and its stock returned.
     *
     * @param ShopOrder            $order
     * @param array<string, mixed> $result
     * @return \Illuminate\Http\RedirectResponse
     */
    private function failedOnReturn(ShopOrder $order, array $result)
    {
        if ((float) $order->received > 0) {
            return $this->cartController()->completeOrder();
        }
        if ((int) $order->status !== ShopOrderStatus::NEW) {
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_cannot_cancel'));
        }
        (new MomoFulfillment())->recordFailure($order, $result);

        return $this->abandon($this->lang('error_failed', ['reason' => mb_substr((string) ($result['message'] ?? ''), 0, 200)]));
    }

    /**
     * Attempt number of the next MoMo page for an order (MoMo needs a new orderId for each).
     *
     * @param ShopOrder $order
     * @param string    $store
     * @return int
     */
    private function nextAttempt(ShopOrder $order, string $store): int
    {
        $prefix = momopayment_order_prefix($store) . '-' . $order->id . '-';
        $current = (string) $order->transaction;

        return str_starts_with($current, $prefix) && ctype_digit(substr($current, strlen($prefix)))
            ? (int) substr($current, strlen($prefix)) + 1
            : 1;
    }

    /**
     * @return string Language of the MoMo page: English for an English storefront, else Vietnamese.
     */
    private function momoLang(): string
    {
        return str_starts_with(strtolower((string) app()->getLocale()), 'en') ? 'en' : 'vi';
    }

    /**
     * Cancel the in-flight order (stock back) and tell the shopper why, in plain words.
     *
     * @param string $message
     * @return \Illuminate\Http\RedirectResponse
     */
    private function abandon(string $message)
    {
        return $this->cartController()->cancelOrder()->with('error', $message);
    }

    /**
     * The shop cart controller that finishes or cancels the order — the site's own override
     * (app/GP247/Shop/Controllers/ShopCartController.php) when one exists.
     *
     * @return ShopCartController
     */
    private function cartController(): ShopCartController
    {
        $class = gp247_namespace(ShopCartController::class);

        return new $class();
    }

    /**
     * Whether this domain (store $storeId) serves the payment request: its own store, or ROOT
     * for a store without a domain.
     *
     * @param PaymentRequest $paymentRequest
     * @param string         $storeId Store of this domain.
     * @return bool
     */
    private function servedHere(PaymentRequest $paymentRequest, string $storeId): bool
    {
        $owner = (string) $paymentRequest->store_id;
        $served = method_exists(PaymentRequestService::class, 'servingStoreId')
            ? PaymentRequestService::servingStoreId($owner)
            : $owner;

        return $served === $storeId;
    }

    private function lang(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/MomoPayment::lang.' . $key, $replace);
    }
}
