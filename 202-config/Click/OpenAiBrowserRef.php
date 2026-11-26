<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Identity\RequestSignals;

/**
 * The ChatGPT Ads pixel's browser reference for a click: the pixel's
 * first-party __obref cookie, which the landing page reads once the pixel
 * has set it and posts to tracking202/static/openai-ref.php with the proof
 * record.php gave it (OpenAiPixelScript), and which the ChatGPT Ads
 * destination sends unchanged as user.obref (DestinationQueue). The page
 * posts again with p202_consent=0 when the visitor withdraws consent there,
 * and the stored reference is removed: OpenAI asks that it stop being sent.
 *
 * The cookie is set only with the pixel's consent, and the page sends it
 * only while p202 consent stands. OpenAI does not document the value's
 * shape; one that is not a cookie value of at most MAX characters is
 * refused, never cut.
 */
final class OpenAiBrowserRef
{
    public const LIFETIME = PageUpdate::LIFETIME;

    public const BODY_LIMIT = 4096;

    /** 202_click_openai_refs.obref's width. */
    public const MAX = 255;

    /** A cookie value (RFC 6265 cookie-octet): printable ASCII but space, ", comma, ; and \. */
    private const VALUE = '/\A[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]{1,255}\z/D';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * The proof record.php hands the page with the pixel's settings: null
     * without the installation's key (logged), so the pixel still loads and
     * only the reference is not kept.
     *
     * @param callable(): ?string $key
     */
    public static function proof(int $clickId, int $landingPageId, int $userId, int $now, callable $key): ?string
    {
        try {
            $secret = $key();
            if ($secret === null) {
                throw new \RuntimeException('installation token key is missing');
            }

            return PurLinkProof::issue(PurLinkProof::BROWSER_REF, $clickId, $landingPageId, $userId, $now, self::LIFETIME, $secret);
        } catch (\Throwable $error) {
            error_log('ChatGPT Ads pixel: no browser reference proof for click ' . $clickId . ': ' . PageUpdate::failureText($error));

            return null;
        }
    }

    /** Whether $value is a reference this keeps. */
    public static function isValue(mixed $value): bool
    {
        return is_string($value) && preg_match(self::VALUE, $value) === 1;
    }

    /**
     * The request's fields: `proof`, then `obref` to keep, or p202_consent
     * as anything but 1 to remove. Any other field, a field sent twice in
     * another case, or an obref that is not one is refused, by its reason.
     *
     * @param array<array-key, mixed> $fields
     * @return array{proof: string, obref: string|null}|string
     */
    public static function parse(array $fields): array|string
    {
        $lowered = [];
        foreach ($fields as $name => $value) {
            $name = strtolower((string) $name);
            if (array_key_exists($name, $lowered)) {
                return 'a field was sent twice';
            }
            $lowered[$name] = $value;
        }
        $unknown = array_diff(array_keys($lowered), ['proof', 'obref', RequestSignals::CONSENT_PARAM]);
        if ($unknown !== []) {
            return 'fields a page does not send: ' . implode(', ', array_map(
                static fn (string|int $name): string => preg_match('/\A[a-z0-9_]{1,40}\z/', (string) $name) === 1 ? (string) $name : '?',
                array_slice(array_values($unknown), 0, 5)
            ));
        }
        $proof = $lowered['proof'] ?? null;
        if (!is_string($proof) || $proof === '') {
            return 'no proof';
        }
        if (!RequestSignals::consentGiven($lowered)) {
            return ['proof' => $proof, 'obref' => null];
        }
        $obref = $lowered['obref'] ?? null;
        if (!self::isValue($obref)) {
            return $obref === null ? 'no obref' : 'the obref is not a cookie value of 1 to ' . self::MAX . ' characters';
        }

        return ['proof' => $proof, 'obref' => $obref];
    }

    /**
     * Keep $obref for the proof's click, or remove the click's with null.
     *
     * @return 'recorded'|'removed'|'refused'
     */
    public function apply(string $proof, ?string $obref, int $now, string $key): string
    {
        $claims = PurLinkProof::verify($proof, PurLinkProof::BROWSER_REF, $now, $key);
        if ($claims === null) {
            return 'refused';
        }
        $work = fn (): bool => (new MysqlClickRepository($this->conn))->recordOpenAiRef(
            $claims['user_id'],
            $claims['click_id'],
            $claims['landing_page_id'],
            $obref,
            $now
        );
        try {
            $done = $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            $done = $work();
        }
        if (!$done) {
            return 'refused';
        }

        return $obref === null ? 'removed' : 'recorded';
    }

    /**
     * The click's stored reference, '' when it has none. On the write handle:
     * the queue reads inside the transaction that sends. Before the 1.9.81
     * upgrade makes the table no click has one.
     */
    public static function of(Connection $conn, int $clickId): string
    {
        try {
            $stmt = $conn->prepareWrite('SELECT obref FROM `' . TableRegistry::CLICK_OPENAI_REFS . '` WHERE click_id = ?');
            $conn->bind($stmt, 'i', [$clickId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            if (!self::tableMissing($e)) {
                throw $e;
            }

            return '';
        }
        $value = $row['obref'] ?? null;

        return self::isValue($value) ? $value : '';
    }

    /** Whether $e, or one it wraps, is the server's 1146 for the table. */
    public static function tableMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (
                Connection::isMysqlError($current, 1146, '')
                && str_contains($current->getMessage(), TableRegistry::CLICK_OPENAI_REFS)
            ) {
                return true;
            }
        }

        return false;
    }
}
