<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Exception\ValidationException;

/**
 * The body of POST /apps/installs, validated strictly (plan §5.2 step 1,
 * CLAUDE.md #4): every field is named, typed exactly as JSON types it (a
 * number sent as a string is refused, never cast — #18), and an unknown
 * field is refused by name rather than ignored.
 *
 *   install_uuid   canonical lower-case UUID, the SDK's per-install id
 *   app_key        the application id the build believes it is
 *   store          "google_play"
 *   referrer       {status: "ok", install_referrer, the four timestamps,
 *                   install_version, google_play_instant}, or
 *                  {status: <a Play error>} with nothing else
 *   first_open_at  unix seconds or null
 *   app_version, sdk_version, os_version   short strings or null
 *   test           bool (default false)
 *   integrity_token  a Play Integrity token or null — stored for the
 *                  verdict worker (PR 6), never judged here
 *   customer       null or {id, type, signature}: a customer id the
 *                  operator's server signed (CustomerClaim), known when
 *                  the body was built; linked after commit
 *
 * `fingerprint()` is the SHA-256 of the canonical body — keys sorted at
 * every level, no insignificant whitespace, integrity_token excluded — so
 * a retry is told from a reused install_uuid carrying other content
 * (CLAUDE.md #15), and so PR 6's requestHash has the same bytes to hash.
 */
final class InstallPayload
{
    public const STORES = ['google_play'];
    /** Play's InstallReferrerResponse codes, lower-cased. */
    public const REFERRER_STATUSES = ['ok', 'feature_not_supported', 'service_unavailable', 'developer_error', 'service_disconnected', 'permission_error'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const TOP = [
        'install_uuid', 'app_key', 'store', 'referrer', 'first_open_at', 'app_version', 'sdk_version', 'os_version', 'test',
        'integrity_token', 'customer',
    ];
    private const REFERRER = [
        'status', 'install_referrer', 'referrer_click_timestamp_seconds', 'install_begin_timestamp_seconds',
        'referrer_click_timestamp_server_seconds', 'install_begin_timestamp_server_seconds', 'install_version', 'google_play_instant',
    ];
    private const MAX_INTEGRITY_TOKEN = 8192;

    /**
     * @param array<string, mixed> $body the decoded body, kept for raw_payload and the fingerprint
     */
    private function __construct(
        public readonly string $installUuid,
        public readonly string $appKey,
        public readonly string $store,
        public readonly string $referrerStatus,
        public readonly ?string $installReferrer,
        public readonly ?int $referrerClickAt,
        public readonly ?int $installBeginAt,
        public readonly ?int $referrerClickServerAt,
        public readonly ?int $installBeginServerAt,
        public readonly ?string $installVersion,
        public readonly ?bool $googlePlayInstant,
        public readonly ?int $firstOpenAt,
        public readonly ?string $appVersion,
        public readonly ?string $sdkVersion,
        public readonly ?string $osVersion,
        public readonly bool $test,
        public readonly ?string $integrityToken,
        public readonly ?CustomerClaim $customer,
        private readonly array $body,
    ) {
    }

    /**
     * @throws ValidationException naming every bad field
     */
    public static function fromDecoded(mixed $body): self
    {
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ValidationException('The install body must be a JSON object', ['body' => 'must be a JSON object']);
        }
        $e = [];
        foreach (array_keys($body) as $key) {
            if (!in_array((string) $key, self::TOP, true)) {
                $e[(string) $key] = 'is not an install field (allowed: ' . implode(', ', self::TOP) . ')';
            }
        }

        $uuid = $body['install_uuid'] ?? null;
        if (!is_string($uuid) || preg_match(self::UUID, $uuid) !== 1) {
            $e['install_uuid'] = 'is required: a UUID in canonical lower-case form (8-4-4-4-12 hexadecimal digits)';
        }
        $appKey = $body['app_key'] ?? null;
        if (!is_string($appKey) || $appKey === '' || strlen($appKey) > 255) {
            $e['app_key'] = 'is required: the application id (package name) of this build';
        }
        $store = $body['store'] ?? null;
        if (!is_string($store) || !in_array($store, self::STORES, true)) {
            $e['store'] = 'is required: one of ' . implode(', ', self::STORES);
        }
        $firstOpen = self::optionalTime($body, 'first_open_at', 'first_open_at', $e);
        $appVersion = self::optionalString($body, 'app_version', 64, $e);
        $sdkVersion = self::optionalString($body, 'sdk_version', 32, $e);
        $osVersion = self::optionalString($body, 'os_version', 32, $e);
        $test = $body['test'] ?? false;
        if (!is_bool($test)) {
            $e['test'] = 'must be true or false';
        }
        $integrity = $body['integrity_token'] ?? null;
        if ($integrity !== null && (!is_string($integrity) || $integrity === '' || strlen($integrity) > self::MAX_INTEGRITY_TOKEN)) {
            $e['integrity_token'] = 'must be null or a Play Integrity token of up to ' . self::MAX_INTEGRITY_TOKEN . ' bytes';
        }

        $customer = CustomerClaim::fromWire($body['customer'] ?? null, 'customer', $e);

        $referrer = $body['referrer'] ?? null;
        $status = null;
        $installReferrer = null;
        $times = ['referrer_click_timestamp_seconds' => null, 'install_begin_timestamp_seconds' => null,
            'referrer_click_timestamp_server_seconds' => null, 'install_begin_timestamp_server_seconds' => null];
        $installVersion = null;
        $instant = null;
        if (!is_array($referrer) || ($referrer !== [] && array_is_list($referrer))) {
            $e['referrer'] = 'is required: an object with at least "status"';
        } else {
            foreach (array_keys($referrer) as $key) {
                if (!in_array((string) $key, self::REFERRER, true)) {
                    $e['referrer.' . $key] = 'is not a referrer field (allowed: ' . implode(', ', self::REFERRER) . ')';
                }
            }
            $status = $referrer['status'] ?? null;
            if (!is_string($status) || !in_array($status, self::REFERRER_STATUSES, true)) {
                $e['referrer.status'] = 'is required: one of ' . implode(', ', self::REFERRER_STATUSES);
                $status = null;
            } elseif ($status !== 'ok') {
                foreach (self::REFERRER as $key) {
                    if ($key !== 'status' && ($referrer[$key] ?? null) !== null) {
                        $e['referrer.' . $key] = 'must be absent or null when status is "' . $status . '" (there is no referrer to report)';
                    }
                }
            } else {
                $installReferrer = $referrer['install_referrer'] ?? null;
                if (!is_string($installReferrer)) {
                    $e['referrer.install_referrer'] = 'is required when status is "ok": the referrer string Play returned ("" for none)';
                    $installReferrer = null;
                }
                foreach (array_keys($times) as $key) {
                    $v = $referrer[$key] ?? null;
                    if (!is_int($v) || $v < 0 || $v > 4294967295) {
                        $e['referrer.' . $key] = 'is required when status is "ok": unix seconds as Play reported them (0 when Play gave none)';
                    } else {
                        $times[$key] = $v;
                    }
                }
                $installVersion = self::optionalString($referrer, 'install_version', 64, $e, 'referrer.install_version');
                $instant = $referrer['google_play_instant'] ?? null;
                if ($instant !== null && !is_bool($instant)) {
                    $e['referrer.google_play_instant'] = 'must be true, false or null';
                    $instant = null;
                }
            }
        }

        if ($e !== []) {
            ksort($e);
            throw new ValidationException('The install is invalid', $e);
        }

        return new self(
            (string) $uuid,
            (string) $appKey,
            (string) $store,
            (string) $status,
            $installReferrer,
            self::zeroAsNull($times['referrer_click_timestamp_seconds']),
            self::zeroAsNull($times['install_begin_timestamp_seconds']),
            self::zeroAsNull($times['referrer_click_timestamp_server_seconds']),
            self::zeroAsNull($times['install_begin_timestamp_server_seconds']),
            $installVersion,
            $instant,
            $firstOpen,
            $appVersion,
            $sdkVersion,
            $osVersion,
            $test === true,
            $integrity,
            $customer,
            $body,
        );
    }

    /** The canonical body without integrity_token, as bytes. */
    public function canonical(): string
    {
        $body = $this->body;
        unset($body['integrity_token']);

        return self::canonicalJson($body);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->canonical());
    }

    /** The body as it was sent, re-encoded, for raw_payload. */
    public function raw(): string
    {
        return self::canonicalJson($this->body);
    }

    /**
     * Keys sorted at every level, lists kept in order, no whitespace,
     * slashes and non-ASCII unescaped, floats with their fraction.
     */
    public static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if ($v !== [] && array_is_list($v)) {
                return array_map($sort, $v);
            }
            ksort($v, SORT_STRING);
            $out = [];
            foreach ($v as $k => $item) {
                $out[(string) $k] = $sort($item);
            }

            return (object) $out;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The install's time on Google's server clock, or null when Play gave none. */
    public function installAt(): ?int
    {
        return $this->installBeginServerAt;
    }

    private static function zeroAsNull(?int $v): ?int
    {
        return $v === null || $v === 0 ? null : $v;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $e
     */
    private static function optionalTime(array $body, string $key, string $path, array &$e): ?int
    {
        $v = $body[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_int($v) || $v < 0 || $v > 4294967295) {
            $e[$path] = 'must be unix seconds or null';

            return null;
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $e
     */
    private static function optionalString(array $body, string $key, int $max, array &$e, ?string $path = null): ?string
    {
        $v = $body[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || $v === '' || strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            $e[$path ?? $key] = 'must be null or a string of 1-' . $max . ' printable characters';

            return null;
        }

        return $v;
    }
}
