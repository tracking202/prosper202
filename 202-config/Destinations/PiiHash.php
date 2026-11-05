<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * Google's normalise-then-SHA-256 rule for user-provided data: the only form
 * an email or phone number leaves this server in. null when the value cannot
 * be normalised, so nothing is guessed.
 */
final class PiiHash
{
    private function __construct()
    {
    }

    public static function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if (substr_count($email, '@') !== 1 || preg_match('/\s/', $email) === 1) {
            return null;
        }
        [$local, $domain] = explode('@', $email);
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            // Gmail ignores dots and a +tag in the local part.
            $local = str_replace('.', '', explode('+', $local, 2)[0]);
        }
        if ($local === '' || $domain === '' || !str_contains($domain, '.')) {
            return null;
        }

        return hash('sha256', $local . '@' . $domain);
    }

    /** E.164 only: a number without its country code is not guessed into one. */
    public static function phone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if (!str_starts_with($phone, '+')) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }

        return hash('sha256', '+' . $digits);
    }
}
