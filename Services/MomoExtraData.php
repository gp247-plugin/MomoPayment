<?php

namespace App\GP247\Plugins\MomoPayment\Services;

/**
 * The `extraData` the plugin attaches to every MoMo payment: what it pays (a checkout order or
 * a payment request), its id, and the store whose MoMo account opened it. MoMo echoes it back
 * in the redirect, the IPN and the query result, inside the signed fields — so the IPN knows
 * which store's keys check its signature and which ledger receives the money.
 *
 * Base64 of a small JSON object, as MoMo's samples do.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-ipn-fulfillment
 * @aidlc-adr momo-payment_pay-with-method-no-sdk
 */
final class MomoExtraData
{
    public const ORDER = 'order';
    public const REQUEST = 'request';

    /**
     * @param string $kind    self::ORDER | self::REQUEST
     * @param string $id      Order id or payment request id.
     * @param string $storeId Store whose MoMo account opened the payment.
     * @return string
     */
    public static function encode(string $kind, string $id, string $storeId): string
    {
        return base64_encode((string) json_encode(['k' => $kind, 'id' => $id, 'store' => $storeId]));
    }

    /**
     * @param mixed $value extraData as received.
     * @return array{k: string, id: string, store: string}|null Null when it is not ours.
     */
    public static function decode($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $json = base64_decode($value, true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !in_array($data['k'] ?? null, [self::ORDER, self::REQUEST], true)) {
            return null;
        }
        foreach (['id', 'store'] as $field) {
            if (!is_string($data[$field] ?? null) || $data[$field] === '') {
                return null;
            }
        }

        return ['k' => $data['k'], 'id' => $data['id'], 'store' => $data['store']];
    }
}
