<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * What a decoded Play Integrity verdict must say for an install to count
 * (plan §5.6, §5.11). Pure: the worker hands it Google's
 * `tokenPayloadExternal`, the registration's package, the install's request
 * hash and when the install arrived.
 *
 * A verdict is `valid` only when every check passes, in this order (the
 * first failure is the one reported):
 *
 *  1. `requestDetails` is an object (else `malformed`);
 *  2. `requestDetails.requestPackageName` — and `appIntegrity.packageName`
 *     when Google sends it — is the registration's package (`wrong_package`):
 *     a token minted by another app says nothing about this one;
 *  3. `requestDetails.requestHash` is the install's request hash, the
 *     SHA-256 of its canonical body (`request_hash`): the token was requested
 *     for THIS install and cannot be moved onto another (IntegrityBinding);
 *  4. `requestDetails.timestampMillis` falls within MAX_TOKEN_AGE before the
 *     install arrived and CLOCK_SKEW after it (`stale` / `future`): a token
 *     harvested earlier cannot be spent later;
 *  5. `appIntegrity.appRecognitionVerdict` is `PLAY_RECOGNIZED`
 *     (`app_not_recognized`): the binary is the one Play distributes;
 *  6. `deviceIntegrity.deviceRecognitionVerdict` contains
 *     `MEETS_DEVICE_INTEGRITY` or `MEETS_STRONG_INTEGRITY`
 *     (`device_integrity`): a real, certified Android device;
 *  7. `accountDetails.appLicensingVerdict` is not `UNLICENSED`
 *     (`unlicensed`). `LICENSED` passes, and so does `UNEVALUATED` or no
 *     licensing verdict at all: Google withholds it whenever an earlier
 *     check failed, and an app may not have licensing responses enabled, so
 *     only an explicit "this user did not get the app from Play" refutes.
 *
 * `MEETS_BASIC_INTEGRITY` alone (a rooted or uncertified device) and
 * `MEETS_VIRTUAL_INTEGRITY` alone (an emulator) do not pass.
 */
final class IntegrityPolicy
{
    /** How long before the install arrived its token may have been issued, seconds. */
    public const MAX_TOKEN_AGE = 600;
    /** How far after the install arrived Google's clock may place the token, seconds. */
    public const CLOCK_SKEW = 120;

    public const DEVICE_LABELS = ['MEETS_DEVICE_INTEGRITY', 'MEETS_STRONG_INTEGRITY'];

    private function __construct()
    {
    }

    /** @param array<mixed> $payload Google's tokenPayloadExternal */
    public static function judge(array $payload, string $packageName, string $requestHash, int $receivedAt): IntegrityJudgement
    {
        $request = $payload['requestDetails'] ?? null;
        $app = is_array($payload['appIntegrity'] ?? null) ? $payload['appIntegrity'] : [];
        $device = is_array($payload['deviceIntegrity'] ?? null) ? $payload['deviceIntegrity'] : [];
        $account = is_array($payload['accountDetails'] ?? null) ? $payload['accountDetails'] : [];

        $labels = [];
        foreach ((array) ($device['deviceRecognitionVerdict'] ?? []) as $label) {
            if (is_string($label)) {
                $labels[] = $label;
            }
        }
        $issuedAt = is_array($request) ? self::seconds($request['timestampMillis'] ?? null) : null;
        $summary = [
            'package' => is_array($request) ? self::text($request['requestPackageName'] ?? null) : null,
            'app_recognition' => self::text($app['appRecognitionVerdict'] ?? null),
            'device' => array_slice($labels, 0, 5),
            'licensing' => self::text($account['appLicensingVerdict'] ?? null),
            'issued_at' => $issuedAt,
            'version_code' => self::text($app['versionCode'] ?? null),
        ];
        $fail = static fn (string $code, string $reason): IntegrityJudgement => new IntegrityJudgement(false, $code, $reason, ['code' => $code] + $summary);

        if (!is_array($request)) {
            return $fail('malformed', 'Google\'s verdict has no requestDetails.');
        }
        $requested = $request['requestPackageName'] ?? null;
        if (!is_string($requested) || $requested !== $packageName) {
            return $fail('wrong_package', 'The token was requested by ' . self::quoted($requested) . ', not by ' . $packageName . '.');
        }
        $appPackage = $app['packageName'] ?? null;
        if ($appPackage !== null && $appPackage !== $packageName) {
            return $fail('wrong_package', 'Google recognised the calling app as ' . self::quoted($appPackage) . ', not ' . $packageName . '.');
        }
        $hash = $request['requestHash'] ?? null;
        if (!is_string($hash) || !hash_equals($requestHash, $hash)) {
            return $fail('request_hash', is_string($hash)
                ? 'The token\'s requestHash is not this install\'s: it was requested for another install body.'
                : 'The token carries no requestHash (a classic request); the SDK must request a standard token bound to the install.');
        }
        if ($issuedAt === null) {
            return $fail('malformed', 'Google\'s verdict has no readable timestampMillis.');
        }
        if ($issuedAt < $receivedAt - self::MAX_TOKEN_AGE) {
            return $fail('stale', 'The token was issued ' . ($receivedAt - $issuedAt) . ' s before the install arrived; at most '
                . self::MAX_TOKEN_AGE . ' s is accepted.');
        }
        if ($issuedAt > $receivedAt + self::CLOCK_SKEW) {
            return $fail('future', 'The token was issued ' . ($issuedAt - $receivedAt) . ' s after the install arrived.');
        }
        $recognition = $app['appRecognitionVerdict'] ?? null;
        if ($recognition !== 'PLAY_RECOGNIZED') {
            return $fail('app_not_recognized', 'Google\'s app recognition verdict is ' . self::quoted($recognition) . ', not PLAY_RECOGNIZED.');
        }
        if (array_intersect($labels, self::DEVICE_LABELS) === []) {
            return $fail('device_integrity', 'The device does not meet device integrity (' . ($labels === [] ? 'no labels' : implode(', ', $labels)) . ').');
        }
        if (($account['appLicensingVerdict'] ?? null) === 'UNLICENSED') {
            return $fail('unlicensed', 'Google says this user did not get the app from Google Play (UNLICENSED).');
        }

        return new IntegrityJudgement(true, 'valid', 'Play Integrity: recognised app, device integrity met, bound to this install.', ['code' => 'valid'] + $summary);
    }

    private static function seconds(mixed $millis): ?int
    {
        if (is_int($millis) && $millis > 0) {
            return intdiv($millis, 1000);
        }
        if (is_string($millis) && preg_match('/^[1-9][0-9]{0,15}$/D', $millis) === 1) {
            return intdiv((int) $millis, 1000);
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) ? mb_strimwidth(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '', 0, 120, '…', 'UTF-8') : null;
    }

    private static function quoted(mixed $value): string
    {
        $text = self::text($value);

        return $text === null ? 'nothing' : '"' . $text . '"';
    }
}
