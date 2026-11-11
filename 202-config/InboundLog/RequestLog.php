<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
use Prosper202\Database\Connection;

/**
 * The classic postback and pixel endpoints' log line: watch() at the top of
 * the endpoint, the shared helpers note what they learn (account, click,
 * conversion, refusal), and one row is written when the request ends,
 * whichever exit it took. Nothing here can stop or change the answer.
 */
final class RequestLog
{
    private static ?self $current = null;

    private int $userId = 0;
    private ?int $clickId = null;
    private ?int $conversionId = null;
    private ?string $transactionId = null;
    private ?string $outcome = null;
    private string $reason = '';
    private ?int $status = null;
    private bool $written = false;
    private ?string $matchMethod = null;
    /** @var array<array-key, mixed>|null the request as the endpoint read it, when that is more than its query */
    private ?array $request = null;

    /**
     * @param array<array-key, mixed> $fields
     * @param (\Closure(int, int): string)|null $presented what the request presented for an account at a time
     */
    private function __construct(
        private readonly InboundLog $log,
        private readonly string $endpoint,
        private readonly string $method,
        private readonly array $fields,
        private readonly ?\Closure $presented = null,
    ) {
    }

    /** @param array<array-key, mixed> $fields the request's query or form, redacted before it is kept */
    public static function watch(\mysqli $db, string $endpoint, string $method, array $fields): self
    {
        // The raw query, as the gate reads it: PHP's parse renames and drops some of it.
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $presented = static fn (int $userId, int $now): string => PostbackAuthGate::forConnection(new Connection($db))
            ->presented($userId, $endpoint, $query, $now);
        $log = new self(new InboundLog($db), $endpoint, strtoupper($method), $fields, $presented);
        self::$current = $log;
        register_shutdown_function([$log, 'write']);

        return $log;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /** For tests: forget the request being watched, unwritten. */
    public static function reset(): void
    {
        if (self::$current !== null) {
            self::$current->written = true;
        }
        self::$current = null;
    }

    public function account(mixed $userId): void
    {
        $id = is_int($userId) ? $userId : (is_string($userId) && ctype_digit($userId) ? (int) $userId : 0);
        if ($id > 0) {
            $this->userId = $id;
        }
    }

    /**
     * The click the request names and how the endpoint found it, said before
     * anything can refuse the request, so a refused one can be recorded
     * later from its row (InboundReplay). Not for a signed PurLink SubID,
     * which its own recorder verifies.
     */
    public function matched(?int $clickId, \Prosper202\Conversion\Ledger\MatchMethod $by): void
    {
        if ($clickId !== null && $clickId > 0) {
            $this->clickId = $clickId;
            $this->matchMethod = $by->value;
        }
    }

    /**
     * The request as the endpoint reads it (gpb.php: its query with a form
     * post's body), kept in place of the query alone; redacted as that is.
     *
     * @param array<array-key, mixed> $fields
     */
    public function request(array $fields): void
    {
        $this->request = $fields;
    }

    public function click(?int $clickId): void
    {
        if ($clickId !== null && $clickId > 0) {
            $this->clickId = $clickId;
        }
    }

    /** What the conversion writer did: recorded, duplicate, or why it recorded nothing. */
    public function converted(string $outcome, ?int $conversionId = null, ?string $transactionId = null, string $reason = ''): void
    {
        $this->outcome = $outcome;
        if ($conversionId !== null && $conversionId > 0) {
            $this->conversionId = $conversionId;
        }
        if ($transactionId !== null && $transactionId !== '') {
            $this->transactionId = $transactionId;
        }
        if ($reason !== '') {
            $this->reason = $reason;
        }
    }

    /** Why p202RecordLegacyConversion() recorded nothing, in the operator's words. */
    public const LEGACY_REASONS = [
        'unknown_click' => 'No click has this id.',
        'foreign_click' => 'The click belongs to another account than the campaign\'s.',
        'campaign_mismatch' => 'The click never visited the campaign the request names.',
        'already_lead' => 'The click had converted already, and the request names no transaction id to tell a new sale from a retry.',
        'duplicate' => 'A conversion with this transaction id is on file already.',
    ];

    public function legacyResult(string $reason): void
    {
        $this->converted($reason, null, null, self::LEGACY_REASONS[$reason] ?? $reason);
    }

    /**
     * What the conversion writer answered (p202RecordConversion()). $clickFound
     * false: the click is not on file; true with no row: something else stopped it.
     */
    public function conversionWritten(int $clickId, int $conversionId, bool $duplicate, string $transactionId, bool $clickFound = true): void
    {
        $this->click($clickId);
        if ($conversionId > 0 && !$duplicate) {
            $this->converted('recorded', $conversionId, $transactionId, 'A conversion was recorded.');
        } elseif ($duplicate) {
            $this->converted($conversionId > 0 ? 'duplicate' : 'already_lead', $conversionId, $transactionId,
                self::LEGACY_REASONS[$conversionId > 0 ? 'duplicate' : 'already_lead']);
        } elseif (!$clickFound) {
            $this->legacyResult('unknown_click');
        } else {
            $this->converted('not_recorded', null, $transactionId, 'The click is on file, and no conversion was recorded.');
        }
    }

    /** A refusal: the outcome a helper already named is kept unless one is given. */
    public function refuse(?string $outcome, string $reason, ?int $status = null): void
    {
        if ($outcome !== null || $this->outcome === null || in_array($this->outcome, ['recorded', 'duplicate'], true)) {
            $this->outcome = $outcome ?? ($status !== null && $status >= 500 ? 'failed' : 'refused');
        }
        $this->reason = $reason;
        if ($status !== null) {
            $this->status = $status;
        }
    }

    public function entry(int $status, string $auth = ''): Entry
    {
        $outcome = $this->outcome;
        $reason = $this->reason;
        if ($outcome === null) {
            $outcome = $status >= 200 && $status < 300 ? 'no_conversion' : ($status >= 500 ? 'failed' : 'refused');
            if ($reason === '') {
                $reason = 'Answered without recording a conversion.';
            }
        }

        return new Entry(
            $this->endpoint,
            $this->method,
            $status,
            $outcome,
            $reason,
            $this->userId,
            $this->clickId,
            $this->conversionId,
            $this->transactionId,
            Redactor::fields($this->request ?? $this->fields, $this->endpoint),
            $auth,
            $this->matchMethod,
        );
    }

    /** What the request presented to authenticate itself; '' when no account was found or it could not be read. */
    private function presentedAuth(): string
    {
        if ($this->presented === null || $this->userId <= 0 || !in_array($this->endpoint, InboundLog::POSTBACK_ENDPOINTS, true)) {
            return '';
        }
        try {
            return ($this->presented)($this->userId, time());
        } catch (\Throwable $e) {
            error_log('Inbound log: what the ' . $this->endpoint . ' request presented could not be read: ' . $e->getMessage());

            return '';
        }
    }

    public function write(): void
    {
        if ($this->written) {
            return;
        }
        $this->written = true;
        try {
            $code = http_response_code();
            $status = is_int($code) && $code > 0 ? $code : ($this->status ?? 200);
            $this->log->append($this->entry($status, $this->presentedAuth()));
        } catch (\Throwable $e) {
            error_log('Inbound log: the ' . $this->endpoint . ' request could not be logged: ' . $e->getMessage());
        }
        if (self::$current === $this) {
            self::$current = null;
        }
    }
}
