<?php

namespace App\GP247\Plugins\MomoPayment\Services;

/**
 * MoMo request/response signatures without an SDK.
 *
 * MoMo signs "field1=value1&field2=value2…" — fields in a fixed order per message, values
 * exactly as sent (no URL encoding), with the partner's accessKey placed in the string even
 * when it is not a field of the message — using HMAC-SHA256 keyed with the secretKey, hex.
 *
 * Field orders come from MoMo's official samples (github.com/momo-wallet/payment, php/PayMoMo,
 * read 2026-11-20) and were checked against the live sandbox on 2026-11-20: create and query
 * succeed, and a refund signed this way gets a business answer while a wrong key gets
 * 11007 "Invalid signature". The IPN order is MoMo's sample only (RISK-TECH-momo-docs-unreachable).
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-hosted-checkout
 * @aidlc-story US-momo-payment-ipn-fulfillment
 * @aidlc-adr momo-payment_pay-with-method-no-sdk
 */
final class MomoSignature
{
    /** POST /v2/gateway/api/create */
    public const CREATE = ['accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo', 'partnerCode',
        'redirectUrl', 'requestId', 'requestType'];

    /** Redirect back to redirectUrl and the IPN posted to ipnUrl. */
    public const RESULT = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType',
        'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];

    /** POST /v2/gateway/api/query */
    public const QUERY = ['accessKey', 'orderId', 'partnerCode', 'requestId'];

    /** POST /v2/gateway/api/refund */
    public const REFUND = ['accessKey', 'amount', 'description', 'orderId', 'partnerCode', 'requestId', 'transId'];

    /**
     * @param array<string, mixed> $data      Message fields (accessKey is taken from $accessKey).
     * @param array<int, string>   $fields    One of the field-order constants.
     * @param string               $accessKey Partner access key.
     * @param string               $secretKey Partner secret key.
     * @return string Hex HMAC-SHA256.
     */
    public static function sign(array $data, array $fields, string $accessKey, string $secretKey): string
    {
        $data['accessKey'] = $accessKey;
        $pairs = [];
        foreach ($fields as $field) {
            $pairs[] = $field . '=' . self::scalar($data[$field] ?? '');
        }

        return hash_hmac('sha256', implode('&', $pairs), $secretKey);
    }

    /**
     * Constant-time check of the `signature` field of a message MoMo sent.
     *
     * @param array<string, mixed> $data      Message including `signature`.
     * @param array<int, string>   $fields    Field order of that message.
     * @param string               $accessKey Partner access key.
     * @param string               $secretKey Partner secret key.
     * @return bool False when no secret is configured — nothing can be trusted then.
     */
    public static function verify(array $data, array $fields, string $accessKey, string $secretKey): bool
    {
        $given = $data['signature'] ?? null;
        if ($secretKey === '' || !is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::sign($data, $fields, $accessKey, $secretKey), strtolower($given));
    }

    /**
     * @param mixed $value
     * @return string Booleans and arrays never appear in MoMo's signed fields; render them empty.
     */
    private static function scalar($value): string
    {
        return is_scalar($value) && !is_bool($value) ? (string) $value : '';
    }
}
