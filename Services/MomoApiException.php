<?php

namespace App\GP247\Plugins\MomoPayment\Services;

/**
 * MoMo could not be reached, answered with something that is not a MoMo result, or refused
 * the operation. The message is MoMo's own (or a technical reason) and never carries a key.
 *
 * @aidlc-unit plugin-momo-payment
 * @aidlc-story US-momo-payment-hosted-checkout
 */
class MomoApiException extends \RuntimeException
{
    private string $momoCode;

    /**
     * @param string $message  Reason, safe to log.
     * @param string $momoCode MoMo resultCode, or a short technical code.
     */
    public function __construct(string $message, string $momoCode = '')
    {
        parent::__construct($message);
        $this->momoCode = $momoCode;
    }

    /**
     * @return string MoMo resultCode, or a short technical code.
     */
    public function momoCode(): string
    {
        return $this->momoCode;
    }
}
