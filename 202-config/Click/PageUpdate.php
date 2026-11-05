<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\Identity\RequestSignals;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

/**
 * 1.9.56's dcs.updateDataObj() click update (redirect/u.php), restored for
 * the click the pageview recorded: record.php hands the page an update proof
 * for that click, and only c1-c4 and the keyword can be changed with it.
 * u.php also rewrote the UTM values, the referrer and, through t202id, the
 * traffic source, cost and payout; those are refused.
 */
final class PageUpdate
{
    public const LIFETIME = PurLinkPageview::LIFETIME;

    public const BODY_LIMIT = 24576;

    /** The request's field (any case, as u.php read it) => the click's value it changes. */
    public const FIELDS = [
        't202c1' => 'c1', 't202c2' => 'c2', 't202c3' => 'c3', 't202c4' => 'c4', 't202kw' => 'keyword',
    ];

    /** Sent by 1.9.56 pages or the transport, and never used to pick the click. */
    private const IGNORED = ['proof', 't202subid', 't202subidorig', RequestSignals::CONSENT_PARAM];

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * record.php's answer for dcs (p202DcsClickJs()): the click's subid and
     * its update proof, only when the visitor consents and the account's
     * privacy setting tracks them in full. Without a key the subid still goes.
     *
     * @param array<string, mixed> $request
     * @param callable(): ?string $key
     */
    public static function clickScript(
        array $request,
        bool $trackingAllowed,
        int $clickId,
        int $landingPageId,
        int $userId,
        int $now,
        callable $key,
    ): string {
        if ($clickId < 1 || !$trackingAllowed || !RequestSignals::consentGiven($request)) {
            return '';
        }
        $payload = ['t202Subid' => (string) $clickId];
        try {
            $secret = $key();
            if ($secret === null) {
                throw new \RuntimeException('installation token key is missing');
            }
            $payload['updateProof'] = PurLinkProof::issue(
                PurLinkProof::UPDATE,
                $clickId,
                $landingPageId,
                $userId,
                $now,
                self::LIFETIME,
                $secret
            );
        } catch (\Throwable $error) {
            error_log('dcs update proof unavailable for click ' . $clickId . ': ' . $error->getMessage());
        }

        return 'try { if (typeof window.p202DcsClick === "function") window.p202DcsClick('
            . json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . "); } catch (e) {}\n";
    }

    /**
     * The values a request may change, as the click recorder reads c1-c4
     * (ClickRequest::text(), %20 as a space); the repository cuts each to its
     * column. An empty value changes nothing, as in 1.9.56. Null when a
     * field is sent twice in different case. consent reads p202_consent in
     * any case, as the fields are read.
     *
     * @param array<array-key, mixed> $request
     * @return array{values: array<string, string>, refused: list<string>, consent: bool}|null
     */
    public static function fields(array $request): ?array
    {
        $lowered = [];
        foreach ($request as $name => $value) {
            $name = strtolower((string) $name);
            if (array_key_exists($name, $lowered)) {
                return null;
            }
            $lowered[$name] = $value;
        }
        $values = [];
        $refused = [];
        foreach ($lowered as $name => $value) {
            // A numeric name is an int key here.
            $name = (string) $name;
            if (in_array($name, self::IGNORED, true)) {
                continue;
            }
            if (!isset(self::FIELDS[$name]) || !is_string($value)) {
                $refused[] = $name;
                continue;
            }
            $text = str_replace('%20', ' ', ClickRequest::text($lowered, $name));
            if ($text !== '') {
                $values[self::FIELDS[$name]] = $text;
            }
        }

        return ['values' => $values, 'refused' => $refused, 'consent' => RequestSignals::consentGiven($lowered)];
    }

    /**
     * Change the proof's click (MysqlClickRepository::recordPageValues()).
     *
     * @param array<string, string> $values fields()'s values
     * @return 'updated'|'unchanged'|'refused'
     */
    public function apply(string $proof, array $values, int $now, string $key): string
    {
        $claims = PurLinkProof::verify($proof, PurLinkProof::UPDATE, $now, $key);
        if ($claims === null) {
            return 'refused';
        }
        $ids = $this->valueIds($values);
        if ($ids === []) {
            return 'unchanged';
        }
        $work = fn (): string => (new MysqlClickRepository($this->conn))->recordPageValues(
            $claims['user_id'],
            $claims['click_id'],
            $claims['landing_page_id'],
            $ids
        ) ? 'updated' : 'refused';
        try {
            $result = $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            $result = $work();
        }
        if ($result === 'updated') {
            // Committed: a failed report refresh is queued, not a failed update (CLAUDE.md #13).
            try {
                ClickRollupSql::rollUpClick($this->conn, $claims['click_id']);
            } catch (\Throwable $error) {
                error_log('dcs update: click ' . $claims['click_id'] . ' changed but its report row was not refreshed: '
                    . $error->getMessage() . '; ' . ClickRollupSql::queueRetry($this->conn, $claims['click_id']));
            }
        }

        return $result;
    }

    /**
     * Dimension rows are found or made before the click's lock, as the click
     * path makes them: the lock must be the transaction's first read.
     *
     * @param array<string, string> $values
     * @return array<string, int>
     */
    private function valueIds(array $values): array
    {
        $tracking = new MysqlTrackingRepository($this->conn);
        $ids = [];
        foreach ($values as $field => $value) {
            $ids[$field] = match ($field) {
                'c1' => $tracking->findOrCreateC1($value),
                'c2' => $tracking->findOrCreateC2($value),
                'c3' => $tracking->findOrCreateC3($value),
                'c4' => $tracking->findOrCreateC4($value),
                'keyword' => $tracking->findOrCreateKeyword($value),
                default => throw new \InvalidArgumentException('Not a field a page may change: ' . $field),
            };
        }

        return $ids;
    }
}
