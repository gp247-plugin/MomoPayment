<?php

if (!function_exists('momopayment_config')) {
    /**
     * Resolve a MoMo setting for a store.
     *
     * Order: the admin_config row of the store (default: the effective store —
     * gp247_plugin_store_id(): admin session / marketplace checkout / storefront domain) → the
     * GLOBAL row → the static default in config.php → $default. Secrets are decrypted by
     * core's read path, so this returns plaintext.
     *
     * @param string          $key     Setting key without the "MomoPayment_" prefix.
     * @param mixed           $default Used when neither the database nor config.php has a value.
     * @param string|int|null $storeId Resolve for this store instead of the effective one (an IPN
     *                                 or a payment request is served with ITS store's account).
     * @return mixed
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-per-store-credentials
     */
    function momopayment_config(string $key, $default = null, $storeId = null)
    {
        $storeId ??= function_exists('gp247_plugin_store_id')
            ? gp247_plugin_store_id()
            : (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);

        $value = gp247_config('MomoPayment_' . $key, $storeId);

        if ($value === null || $value === '') {
            $value = config('Plugins/MomoPayment.' . $key, $default);
        }

        return $value ?? $default;
    }
}

if (!function_exists('momopayment_test_mode')) {
    /**
     * Whether the store is using MoMo's test (sandbox) environment.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return bool
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-per-store-credentials
     */
    function momopayment_test_mode($storeId = null): bool
    {
        return (bool) (int) momopayment_config('test_mode', 1, $storeId);
    }
}

if (!function_exists('momopayment_credentials')) {
    /**
     * The MoMo account (partnerCode, accessKey, secretKey) of the mode in use for a store.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return array{partner_code: string, access_key: string, secret_key: string} Empty strings when not set.
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-per-store-credentials
     */
    function momopayment_credentials($storeId = null): array
    {
        $suffix = momopayment_test_mode($storeId) ? '_test' : '_live';
        $out = [];
        foreach (['partner_code', 'access_key', 'secret_key'] as $key) {
            $out[$key] = trim((string) momopayment_config($key . $suffix, '', $storeId));
        }

        return $out;
    }
}

if (!function_exists('momopayment_configured')) {
    /**
     * Whether a store has all three keys for the mode it is in.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return bool
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-per-store-credentials
     */
    function momopayment_configured($storeId = null): bool
    {
        return !in_array('', momopayment_credentials($storeId), true);
    }
}

if (!function_exists('momopayment_endpoint')) {
    /**
     * MoMo API host for the mode a store is in.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return string
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-per-store-credentials
     */
    function momopayment_endpoint($storeId = null): string
    {
        return momopayment_test_mode($storeId) ? 'https://test-payment.momo.vn' : 'https://payment.momo.vn';
    }
}

if (!function_exists('momopayment_order_prefix')) {
    /**
     * Prefix of every order id sent to MoMo.
     *
     * WHY: MoMo requires orderId to be unique per partnerCode, and the public sandbox
     * partnerCode is shared by every developer — "order 1" of two sites would collide. The
     * site owner may set a prefix; otherwise one is derived from APP_KEY and the store, so it
     * is stable for the site and different between sites.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return string Letters and digits only, at most 12 characters.
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-hosted-checkout
     * @aidlc-adr momo-payment_pay-with-method-no-sdk
     */
    function momopayment_order_prefix($storeId = null): string
    {
        $prefix = substr((string) preg_replace('/[^A-Za-z0-9]/', '', (string) momopayment_config('order_prefix', '', $storeId)), 0, 12);
        if ($prefix !== '') {
            return $prefix;
        }
        $storeId ??= function_exists('gp247_plugin_store_id') ? gp247_plugin_store_id() : '';

        return 'GP' . substr(hash('sha256', (string) config('app.key') . '|' . $storeId), 0, 6);
    }
}

if (!function_exists('momopayment_route_url')) {
    /**
     * Absolute URL of a plugin route, computed per request so it follows the store's current
     * domain instead of a stored value that can drift. Falls back to the plain path when the
     * route is not registered yet (plugin just installed, console or test context).
     *
     * @param string $name Route name.
     * @param string $path Path used when the route is not registered.
     * @return string
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-hosted-checkout
     */
    function momopayment_route_url(string $name, string $path): string
    {
        return \Illuminate\Support\Facades\Route::has($name) ? route($name) : url($path);
    }
}

if (!function_exists('momopayment_return_url')) {
    /**
     * Where MoMo sends the shopper back after a checkout payment.
     *
     * @return string
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-hosted-checkout
     */
    function momopayment_return_url(): string
    {
        return momopayment_route_url('momo-payment.return', 'plugin/momo-payment/return');
    }
}

if (!function_exists('momopayment_ipn_url')) {
    /**
     * Where MoMo posts the payment result (IPN).
     *
     * @return string
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-ipn-fulfillment
     */
    function momopayment_ipn_url(): string
    {
        return momopayment_route_url('momo-payment.ipn', 'plugin/momo-payment/ipn');
    }
}

if (!function_exists('momopayment_payment_request_return_url')) {
    /**
     * Where MoMo sends a payer back after paying a payment request (pay link).
     *
     * @return string
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-payment-request
     */
    function momopayment_payment_request_return_url(): string
    {
        return momopayment_route_url('momo-payment.payment_request.return', 'plugin/momo-payment/payment-request/return');
    }
}

if (!function_exists('momopayment_register_payment_gateway')) {
    /**
     * Offer MoMo to the shop's payment requests (pay links, refunds) — only when the shop has
     * that feature; an older shop simply never sees the gateway.
     *
     * @return void
     *
     * @aidlc-unit plugin-momo-payment
     * @aidlc-story US-momo-payment-payment-request
     */
    function momopayment_register_payment_gateway(): void
    {
        if (!interface_exists(\GP247\Shop\Payment\Contracts\PaymentGateway::class)) {
            return;
        }
        // WHY a whole-array write: dot-notation would split a gateway key containing dots.
        $gateways = (array) config('gp247-config.payment.gateways', []);
        $gateways['MomoPayment'] = [
            'label' => 'MoMo',
            'capabilities' => ['collect', 'refund'],
            'driver' => \App\GP247\Plugins\MomoPayment\Services\MomoPaymentRequestGateway::class,
        ];
        config(['gp247-config.payment.gateways' => $gateways]);
    }
}
