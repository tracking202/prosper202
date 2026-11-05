<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose signature covers the payment but not the click (Zaxaa's
 * hash_key signs the receipt and amount, joined with no separator, never the
 * custom field that names the click). One signed body could otherwise be
 * re-aimed at every click of the account, or have digits moved between its
 * receipt and amount, so the processor refuses a sale whose signed text is
 * already on file for the account under another click or receipt.
 */
interface SignsThePaymentNotTheClick
{
    /** The part of the signed text a sale's transaction id and amount make up, as the signature joins them. */
    public function signedPayment(string $transactionId, string $amount): string;
}
