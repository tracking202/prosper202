<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\ClickBank;

/**
 * How ClickBank encrypts an instant notification (version 6.0 and later), as
 * the PHP sample in ClickBank's INS article decrypts it: AES-256-CBC, keyed
 * with the first 32 hexadecimal digits of the secret key's SHA-1 taken as 32
 * bytes of text, with the IV sent beside the notification; trailing control
 * bytes are trimmed after the padding.
 *
 * cb202.php decrypted with AES-128-CBC until 1.9.79, which reads only the
 * first 16 bytes of that key: no notification encrypted as ClickBank's
 * sample decrypts it ever decrypted there.
 */
final class ClickBankCipher
{
    public const CIPHER = 'AES-256-CBC';

    private function __construct()
    {
    }

    public static function key(string $secret): string
    {
        return substr(sha1($secret), 0, 32);
    }

    /**
     * The order a notification carries when it decrypts with the secret into
     * a JSON object naming its transactionType, or null. A wrong key, and the
     * right key over bytes that are not an order, both answer null, so no
     * answer of the endpoint says whether a ciphertext's padding was good.
     *
     * @return array<string, mixed>|null
     */
    public static function decrypt(string $ciphertext, string $iv, string $secret): ?array
    {
        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::key($secret), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return null;
        }
        // 7.0 is the first version that says it is UTF-8: a buyer's name in
        // another encoding must not make the order unreadable.
        $order = json_decode(trim($plain, "\0..\32"), true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($order) || array_is_list($order) || !is_string($order['transactionType'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $order */
        return $order;
    }
}
