<?php

namespace App\GP247\Plugins\MomoPayment\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal MoMo REST client — the three calls the plugin needs (create, query, refund).
 *
 * WHY no SDK: the plugin is installed by folder/ZIP on hosts that cannot run `composer
 * require`, and MoMo's own PHP library has not been updated since 2022
 * (ADR momo-payment_pay-with-method-no-sdk). Every call is a signed JSON POST; the keys only
 * sign, they are never sent.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-hosted-checkout
 * @aidlc-story US-momo-payment-payment-request
 * @aidlc-adr momo-payment_pay-with-method-no-sdk
 */
class MomoApi
{
    /** MoMo answers payment calls within its own 30-second window. */
    private const TIMEOUT = 30;

    /** @var array{partner_code: string, access_key: string, secret_key: string} */
    private array $credentials;

    private string $baseUrl;

    /**
     * @param array{partner_code: string, access_key: string, secret_key: string} $credentials
     * @param string                                                             $baseUrl test-payment.momo.vn or payment.momo.vn
     */
    public function __construct(array $credentials, string $baseUrl)
    {
        $this->credentials = $credentials;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Client for a store: its account and environment for the mode it is in.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return self
     */
    public static function forStore($storeId = null): self
    {
        return new self(momopayment_credentials($storeId), momopayment_endpoint($storeId));
    }

    /**
     * @return string Partner code of this account.
     */
    public function partnerCode(): string
    {
        return $this->credentials['partner_code'];
    }

    /**
     * Whether all three keys are set.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return !in_array('', $this->credentials, true);
    }

    /**
     * Check a message MoMo sent (redirect or IPN) against this account.
     *
     * @param array<string, mixed> $result
     * @return bool
     */
    public function verifyResult(array $result): bool
    {
        return $this->configured()
            && hash_equals($this->credentials['partner_code'], (string) ($result['partnerCode'] ?? ''))
            && MomoSignature::verify($result, MomoSignature::RESULT, $this->credentials['access_key'], $this->credentials['secret_key']);
    }

    /**
     * POST /v2/gateway/api/create
     *
     * @param array<string, mixed> $params orderId, amount, orderInfo, redirectUrl, ipnUrl, extraData, requestType, lang.
     * @return array<string, mixed> MoMo's answer (resultCode 0 carries payUrl).
     * @throws MomoApiException
     */
    public function create(array $params): array
    {
        return $this->send('/v2/gateway/api/create', $params + ['requestId' => (string) $params['orderId']], MomoSignature::CREATE);
    }

    /**
     * POST /v2/gateway/api/query — the payment's state as MoMo has it.
     *
     * @param string $orderId MoMo order id the plugin sent.
     * @return array<string, mixed>
     * @throws MomoApiException
     */
    public function query(string $orderId): array
    {
        return $this->send('/v2/gateway/api/query', [
            'orderId' => $orderId,
            'requestId' => 'Q' . (int) (microtime(true) * 1000) . random_int(100, 999),
            'lang' => 'vi',
        ], MomoSignature::QUERY);
    }

    /**
     * POST /v2/gateway/api/refund
     *
     * @param array<string, mixed> $params orderId (new, unique), amount, transId (of the payment), description.
     * @return array<string, mixed>
     * @throws MomoApiException
     */
    public function refund(array $params): array
    {
        return $this->send('/v2/gateway/api/refund', $params + ['requestId' => (string) $params['orderId'], 'lang' => 'vi'], MomoSignature::REFUND);
    }

    /**
     * @param string               $path
     * @param array<string, mixed> $params
     * @param array<int, string>   $fields Signature field order.
     * @return array<string, mixed> A MoMo result (has resultCode), whatever the HTTP status.
     * @throws MomoApiException
     */
    private function send(string $path, array $params, array $fields): array
    {
        if (!$this->configured()) {
            throw new MomoApiException('MoMo keys are not configured', 'not_configured');
        }
        $body = ['partnerCode' => $this->credentials['partner_code']] + $params;
        $body['signature'] = MomoSignature::sign($body, $fields, $this->credentials['access_key'], $this->credentials['secret_key']);

        try {
            $response = Http::acceptJson()->asJson()->timeout(self::TIMEOUT)->connectTimeout(10)->post($this->baseUrl . $path, $body);
        } catch (ConnectionException $e) {
            throw new MomoApiException('Cannot reach MoMo: ' . $e->getMessage(), 'connection_error');
        }

        $json = $response->json();
        // WHY not only 2xx: MoMo answers a refused operation with HTTP 4xx and a normal result body.
        if (!is_array($json) || !array_key_exists('resultCode', $json)) {
            throw new MomoApiException('Unexpected answer from MoMo (HTTP ' . $response->status() . ')', 'http_' . $response->status());
        }

        return $json;
    }
}
