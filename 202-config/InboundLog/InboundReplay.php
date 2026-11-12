<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\AmountOutOfRange;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Database\Connection;

/**
 * "Record it now": the conversion or event of a postback or pixel the log
 * kept and the install refused, recorded on the owner's say-so (Setup ›
 * Inbound Log, POST /conversions/inbound-log/{id}/record).
 *
 * The request is rebuilt from the row's kept fields and handed to the
 * recorders its endpoint calls: p202RecordWebEvent() for one that carries
 * an event, then p202RecordLegacyConversion(), each with the endpoint's own
 * scope (KINDS). The click must be the account's, a pb row's click must
 * have visited the campaign it names, a transaction id de-duplicates, and
 * without one a click converts once. Only what the owner can vouch for is
 * offered: a request refused for its credentials or by an error here, whose
 * every kept value is whole. A pixel's click and a form post's body are
 * kept from 1.9.80 on (match_method on the row); a row logged without them
 * says so and is not offered.
 *
 * Pressing twice records once. The row is taken first (claimReplay()), so
 * one caller writes; and the recorders answer a second attempt as a
 * duplicate (a transaction id, an event id) or as already converted
 * (neither), so a row given back after an error is safe to ask again.
 *
 * Another kind of request (a payment notification) is offered by adding its
 * endpoint to KINDS with a recorder of its own in write(); the claim, the
 * finish and the release on the row are the same for every kind.
 */
final class InboundReplay
{
    /**
     * Per endpoint, what its live script hands the recorder: the pixel type
     * and source of its rows, whether it names a campaign (acip), whether
     * the click must have visited that campaign, whether it reads amount
     * and a transaction id, whether it carries product lines, whether the
     * traffic source is told, and whether the click comes from the visitor's
     * browser (kept on the row) or the request's subid.
     */
    private const KINDS = [
        'pb' => ['pixel_type' => 2, 'source' => 'legacy_pixel', 'acip' => true, 'visited' => true, 'amount' => false,
            'txid' => true, 'items' => false, 'notify' => true, 'browser' => false],
        'gpb' => ['pixel_type' => 2, 'source' => 'postback', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'items' => true, 'notify' => true, 'browser' => false],
        'px' => ['pixel_type' => 1, 'source' => 'legacy_pixel', 'acip' => true, 'visited' => false, 'amount' => false,
            'txid' => false, 'items' => false, 'notify' => false, 'browser' => true],
        'gpx' => ['pixel_type' => 1, 'source' => 'pixel', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'items' => true, 'notify' => false, 'browser' => true],
        'upx' => ['pixel_type' => 3, 'source' => 'universal_pixel', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'items' => true, 'notify' => true, 'browser' => true],
    ];
    public const OUTCOMES = ['unauthenticated', 'failed'];
    /**
     * The names a request links its customer by (P202_CUSTOMER_NAMES and
     * the signature of one). The log never keeps their values, so a row
     * that carried one cannot be recorded with its customer and is not offered.
     */
    private const CUSTOMER_FIELDS = ['cust', 'customer_ref', 'cust_sig'];
    public const USER_AGENT = 'Prosper202 Inbound Log';
    /** No visitor's address is kept by the log, so a conversion recorded from it stores none. */
    private const NO_VISITOR = '';

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** The order number names a request may carry, first non-blank wins (P202_ORDER_NUMBER_NAMES). */
    public const ORDER_NUMBER_NAMES = ['txid', 'transaction_id', 'transactionid', 'order_id', 'orderid', 'oid', 't202txid'];

    /**
     * What the owner is asked before a refused request is recorded: what it
     * says (its click, amount, event and transaction id, and a reversal as a
     * reversal), when it arrived, that nothing vouched for it when it carried
     * no token, and which of the account's destinations will not take it for
     * its age.
     *
     * @param array<string, mixed> $row a log row as InboundLog shapes it
     * @param list<array<string, mixed>> $destinations the account's, as DestinationStore::list() gives them
     */
    public static function question(array $row, array $destinations, int $now): string
    {
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $text = static fn (string $name): ?string => is_string($fields[$name] ?? null) && trim($fields[$name]) !== ''
            ? trim($fields[$name]) : null;
        $transaction = null;
        foreach (self::ORDER_NUMBER_NAMES as $name) {
            $transaction ??= $text($name);
        }
        $amount = $text('amount');
        $reversed = strtolower((string) $text('status')) === 'reversed';
        $negative = $amount !== null && is_numeric($amount) && (float) $amount < 0;

        $said = [];
        $click = $row['click_id'] ?? $text('subid') ?? $text('sid');
        if (is_scalar($click)) {
            $said[] = 'click ' . $click;
        }
        if ($reversed) {
            $said[] = 'a reversal of transaction ' . ($transaction ?? '(none named)')
                . ($text('reversal_id') !== null ? ' (reversal id ' . $text('reversal_id') . ')' : '');
        } elseif ($negative) {
            $said[] = 'amount ' . $amount . ', which reverses transaction ' . ($transaction ?? '(none named)') . ' if it is on file';
        } else {
            if ($amount !== null) {
                $said[] = 'amount ' . $amount;
            }
            if ($transaction !== null) {
                $said[] = 'transaction id ' . $transaction;
            }
        }
        if ($text('event') !== null) {
            $said[] = 'event ' . $text('event');
        }

        $arrived = (int) ($row['received_at'] ?? 0);

        return ($reversed || $negative ? 'Record this reversal now? It takes money off a sale already recorded. ' : 'Record this request now? ')
            . 'It says: ' . ($said === [] ? 'nothing beyond its click' : implode(', ', $said)) . '. '
            . (in_array($row['auth'] ?? null, ['none', 'invalid'], true)
                ? 'It did not carry your postback token, so nothing shows who sent it: record it only if you know it is real. '
                : '')
            . 'It is recorded as it would have been when it arrived, ' . date('M j, Y g:i a', $arrived) . '.'
            . self::tooOldFor($destinations, $arrived, $now);
    }

    /**
     * The sentence naming the account's switched-on destinations that will
     * not take a conversion this old ('' when none): GA4 takes events up to
     * 72 hours old.
     *
     * @param list<array<string, mixed>> $destinations
     */
    public static function tooOldFor(array $destinations, int $arrived, int $now): string
    {
        if ($now - $arrived <= \Prosper202\Destinations\Ga4Destination::MAX_AGE_SECONDS) {
            return '';
        }
        $names = [];
        foreach ($destinations as $destination) {
            if (($destination['enabled'] ?? false) === true && ($destination['type'] ?? '') === \Prosper202\Destinations\Ga4Destination::TYPE) {
                $names[] = (string) ($destination['name'] ?? 'Google Analytics');
            }
        }
        if ($names === []) {
            return '';
        }

        return ' Google Analytics takes conversions up to 72 hours old, so ' . implode(', ', $names)
            . (count($names) === 1 ? ' does' : ' do') . ' not receive this one; your reports and other destinations do.';
    }

    /**
     * Why "record it now" is not offered for the row, or null when it is.
     *
     * @param array<string, mixed> $row a log row as InboundLog shapes it
     */
    public static function refusalOf(array $row): ?string
    {
        if (($row['user_id'] ?? null) === null) {
            return 'No account was found for this request, so there is no account to record it on.';
        }
        if (($row['recorded_later_at'] ?? null) !== null) {
            return 'This request was recorded from the log already.';
        }
        if (($row['conversion_id'] ?? null) !== null) {
            return 'This request has its conversion already.';
        }
        $endpoint = (string) ($row['endpoint'] ?? '');
        $kind = self::KINDS[$endpoint] ?? null;
        if ($kind === null) {
            return 'A payment notification is recorded from its signed body, which the log does not keep.';
        }
        if (!in_array($row['outcome'] ?? null, self::OUTCOMES, true)) {
            return 'Recording it again would be refused for the same reason.';
        }
        $request = self::request($row['fields'] ?? null);
        if ($request === null) {
            return 'The log did not keep the whole request, so recording from it could record something else.';
        }
        foreach (self::CUSTOMER_FIELDS as $name) {
            if (is_array($row['fields'] ?? null) && ($row['fields'][$name] ?? '') !== '') {
                return 'It names the customer who bought (' . $name . '), which the log never keeps, so recording it here would'
                    . ' lose that customer\'s link to the sale. Ask the sender to send it again.';
            }
        }
        $kept = ($row['match_method'] ?? null) !== null;
        if ($kind['browser'] && (!$kept || MatchMethod::tryFrom((string) $row['match_method']) === null)) {
            return 'This pixel request was logged without the click its visitor\'s browser named, so the log cannot say'
                . ' which click converted.';
        }
        if ($kept && MatchMethod::tryFrom((string) $row['match_method']) === null) {
            return 'The log does not say how this request\'s click was found, so recording it could credit another click.';
        }
        if (!$kind['browser'] && ($row['method'] ?? '') !== 'GET' && !$kept) {
            return 'It was sent as a form post, and this row was logged with only what its URL carried.';
        }
        if (self::clickId($row, $request) === null) {
            return 'Its subid is not a plain click id, so the log cannot say which click it names.';
        }
        if ($kind['acip'] && ClickId::parse($request['acip'] ?? null) === null) {
            return 'It names no campaign (acip), which ' . $endpoint . '.php records on.';
        }

        return null;
    }

    /**
     * The request as the log kept it: the classic parameters' values, or
     * null when one was redacted or cut short, or the fields were not kept
     * whole. The other names' values are never kept and are left out.
     *
     * @return array<string, string>|null
     */
    private static function request(mixed $fields): ?array
    {
        if ($fields === null) {
            return []; // a request that carried no parameter is stored with none
        }
        if (!is_array($fields) || isset($fields['_unreadable']) || isset($fields['_more_fields'])) {
            return null;
        }
        $request = [];
        foreach (Redactor::classicFields() as $name) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            $value = $fields[$name];
            if (!is_string($value) || $value === Redactor::REDACTED || str_ends_with($value, '…')) {
                return null;
            }
            $request[$name] = $value;
        }

        return $request;
    }

    /**
     * The click the row names: the one its endpoint found, where it said how
     * (match_method), else the request's own subid.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $request
     */
    private static function clickId(array $row, array $request): ?int
    {
        if (($row['match_method'] ?? null) !== null) {
            return is_int($row['click_id'] ?? null) && $row['click_id'] > 0 ? $row['click_id'] : null;
        }
        if (self::KINDS[(string) $row['endpoint']]['browser']) {
            return null;
        }

        return ClickId::parse($request['subid'] ?? null)
            ?? ($row['endpoint'] === 'gpb' ? ClickId::parse($request['sid'] ?? null) : null);
    }

    /**
     * @return array{status: string, message: string, conversion_id: int|null, click_id: int|null}
     *         status: recorded, duplicate (on file already under its transaction or event id),
     *         already_recorded, in_progress, refused (the recorder's own refusal), not_recordable,
     *         not_found or failed
     */
    public function replay(int $userId, int $inboundId, ?int $now = null): array
    {
        $now ??= time();
        $log = new InboundLog($this->db);
        $row = $log->find($userId, $inboundId);
        if ($row === null) {
            return self::answer('not_found', 'There is no such request in your inbound log; it may have been pruned.');
        }
        if ($row['recorded_later_at'] !== null) {
            return self::already($row);
        }
        $refusal = self::refusalOf($row);
        if ($refusal !== null) {
            return self::answer('not_recordable', $refusal);
        }
        if (!$log->claimReplay($userId, $inboundId, $now)) {
            $again = $log->find($userId, $inboundId);

            return $again !== null && $again['recorded_later_at'] !== null
                ? self::already($again)
                : self::answer('in_progress', 'This request is being recorded right now. Reload the log in a moment.');
        }

        $request = self::request($row['fields']) ?? [];
        $clickId = (int) self::clickId($row, $request);
        try {
            $outcome = $this->write($userId, $row, $request, $clickId);
        } catch (AmountOutOfRange | ReversalException $refused) {
            $log->releaseReplay($userId, $inboundId, $now);

            return self::answer('refused', $refused->getMessage(), null, $clickId);
        } catch (\Throwable $failed) {
            error_log('Inbound log: request ' . $inboundId . ' could not be recorded: ' . $failed->getMessage());
            try {
                $log->releaseReplay($userId, $inboundId, $now);
            } catch (\Throwable $releaseFailed) {
                error_log('Inbound log: request ' . $inboundId . ' could not be given back: ' . $releaseFailed->getMessage());
            }

            return self::answer('failed', 'It could not be recorded just now. Try again: what did get recorded is found,'
                . ' not recorded twice.', null, $clickId);
        }
        if ($outcome['status'] === 'refused') {
            $log->releaseReplay($userId, $inboundId, $now);

            return self::answer('refused', $outcome['message'], null, $clickId);
        }
        try {
            $log->finishReplay($userId, $inboundId, $outcome['conversion_id'], $now);
        } catch (\Throwable $noteFailed) {
            // What was recorded stands; only the log row's note of it is missing.
            error_log('Inbound log: request ' . $inboundId . ' was recorded and the log row could not be marked: '
                . $noteFailed->getMessage());
        }

        $late = '';
        if ($outcome['status'] === 'recorded') {
            try {
                $late = self::tooOldFor(
                    (new \Prosper202\Destinations\DestinationStore(new Connection($this->db)))->list($userId),
                    (int) $row['received_at'],
                    $now
                );
            } catch (\Throwable $e) {
                error_log('Inbound log: the destinations could not be read after recording request ' . $inboundId . ': ' . $e->getMessage());
            }
        }

        return self::answer($outcome['status'], $outcome['message'] . $late, $outcome['conversion_id'], $clickId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status: string, message: string, conversion_id: int|null, click_id: int|null}
     */
    private static function already(array $row): array
    {
        return self::answer(
            'already_recorded',
            'This request was recorded from the log already'
                . ($row['conversion_id'] !== null ? ', as conversion ' . $row['conversion_id'] . '.' : '.'),
            $row['conversion_id'],
            $row['click_id']
        );
    }

    /**
     * What the endpoint's recorders answered: recorded or duplicate (with
     * the conversion, when there is one), or refused with the sentence why.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $request
     * @return array{status: string, message: string, conversion_id: int|null}
     */
    private function write(int $userId, array $row, array $request, int $clickId): array
    {
        require_once dirname(__DIR__) . '/static-endpoint-helpers.php';

        $endpoint = (string) $row['endpoint'];
        $kind = self::KINDS[$endpoint];
        $refused = static fn (string $why): array => ['status' => 'refused', 'message' => 'Nothing was recorded. ' . $why, 'conversion_id' => null];
        // How the endpoint found the click, as it said; a server postback logged before it said is its subid's.
        $matchedBy = MatchMethod::tryFrom((string) ($row['match_method'] ?? '')) ?? MatchMethod::CLICK_ID;

        $campaignId = null;
        if ($kind['acip']) {
            $campaignId = $this->campaignOf($userId, (int) ClickId::parse($request['acip'] ?? null));
            if ($campaignId === null) {
                return $refused('The campaign the request names (acip ' . $request['acip'] . ') is not one of your account\'s.');
            }
        }
        // The click must be this account's, whatever the request named; pb.php's must have visited its campaign.
        $scope = ['user_id' => $userId] + ($kind['visited'] ? ['campaign_id' => $campaignId] : []);

        // An event goes to the click's goals first, as on the endpoint; a campaign without goals records its plain conversion.
        // Dated when it arrived, as the plain conversion below is: it counts on the day it happened.
        $event = p202RecordWebEvent($this->db, $clickId, $request, $matchedBy, $scope + ['browser' => false, 'at' => (int) $row['received_at']]);
        if ($event !== null && $event['status'] !== 'no_goals') {
            return match ($event['status']) {
                'recorded' => self::eventRecorded($event['result']),
                'not_found' => $refused(RequestLog::LEGACY_REASONS[$event['reason']] ?? 'No click has this id.'),
                default => $refused((string) ($event['message'] ?? 'The event was refused.')),
            };
        }

        $amount = $kind['amount'] && array_key_exists('amount', $request) && is_numeric($request['amount']);
        $outcome = p202RecordLegacyConversion($this->db, $clickId, $kind['pixel_type'], $matchedBy, [
            'ip' => self::NO_VISITOR,
            'user_agent' => self::USER_AGENT,
            'transaction_id' => $kind['txid'] ? p202ExtractTransactionId($request) : '',
            'source' => $kind['source'],
            // Dated when the sender reported it, so it counts on the day it happened.
            'conv_time' => (int) $row['received_at'],
            'notify_traffic_source' => $kind['notify'],
            'use_pixel_payout' => $amount,
            'payout' => $amount ? $request['amount'] : '0',
            'items' => $kind['items'] ? p202ExtractItems($request) : [],
            'event_name' => $event['event_name'] ?? null,
        ] + $scope + p202ExtractReversal($request));

        if ($outcome['conv_id'] <= 0) {
            return $refused(RequestLog::LEGACY_REASONS[$outcome['reason']] ?? 'The recorder refused it (' . $outcome['reason'] . ').');
        }

        return $outcome['recorded']
            ? ['status' => 'recorded', 'message' => 'Recorded as conversion ' . $outcome['conv_id'] . ' on click ' . $clickId . '.',
                'conversion_id' => $outcome['conv_id']]
            : ['status' => 'duplicate', 'message' => 'A conversion with this transaction id was on file already (conversion '
                . $outcome['conv_id'] . '); nothing was added.', 'conversion_id' => $outcome['conv_id']];
    }

    /**
     * @param array<string, mixed> $result GoalEngine::ingest()'s answer
     * @return array{status: string, message: string, conversion_id: int|null}
     */
    private static function eventRecorded(array $result): array
    {
        if ($result['accepted'] === []) {
            return ['status' => 'duplicate', 'message' => 'The event was on file already; nothing was added.', 'conversion_id' => null];
        }
        $conversion = null;
        foreach ($result['outcomes'] as $reached) {
            if (is_int($reached['conversion_id'] ?? null) && $reached['conversion_id'] > 0) {
                $conversion = $reached['conversion_id'];
                break;
            }
        }

        return [
            'status' => 'recorded',
            'message' => $conversion !== null
                ? 'The event was recorded, and its goal recorded conversion ' . $conversion . '.'
                : 'The event was recorded. It reached no goal that records a conversion.',
            'conversion_id' => $conversion,
        ];
    }

    /** The account's campaign with this public id (a pb URL's acip), or null. */
    private function campaignOf(int $userId, int $publicId): ?int
    {
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public = ? AND user_id = ?'
            . ' ORDER BY aff_campaign_id LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$publicId, $userId]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['aff_campaign_id'];
    }

    /** @return array{status: string, message: string, conversion_id: int|null, click_id: int|null} */
    private static function answer(string $status, string $message, mixed $conversionId = null, mixed $clickId = null): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'conversion_id' => is_int($conversionId) ? $conversionId : null,
            'click_id' => is_int($clickId) ? $clickId : null,
        ];
    }
}
