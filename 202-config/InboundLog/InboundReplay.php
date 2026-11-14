<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Click\ClickId;
use Prosper202\Click\FirstVisit;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Conversion\Ledger\AmountOutOfRange;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Database\Connection;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\OpenAiAdsDestination;

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
     * and a transaction id, whether it reads status=reversed, whether it
     * carries product lines, whether the traffic source is told, and whether
     * the click comes from the visitor's browser (kept on the row) or the
     * request's subid.
     */
    private const KINDS = [
        'pb' => ['pixel_type' => 2, 'source' => 'legacy_pixel', 'acip' => true, 'visited' => true, 'amount' => false,
            'txid' => true, 'reversal' => true, 'items' => false, 'notify' => true, 'browser' => false],
        'gpb' => ['pixel_type' => 2, 'source' => 'postback', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'reversal' => true, 'items' => true, 'notify' => true, 'browser' => false],
        'px' => ['pixel_type' => 1, 'source' => 'legacy_pixel', 'acip' => true, 'visited' => false, 'amount' => false,
            'txid' => false, 'reversal' => false, 'items' => false, 'notify' => false, 'browser' => true],
        'gpx' => ['pixel_type' => 1, 'source' => 'pixel', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'reversal' => false, 'items' => true, 'notify' => false, 'browser' => true],
        'upx' => ['pixel_type' => 3, 'source' => 'universal_pixel', 'acip' => false, 'visited' => false, 'amount' => true,
            'txid' => true, 'reversal' => false, 'items' => true, 'notify' => true, 'browser' => true],
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
     * What the endpoint's recorder is handed for the row, read once for both
     * write() and question(), so the question says what is recorded: the
     * amount only where the endpoint reads one (pb.php and px.php take the
     * campaign's payout), the transaction id only where it reads one (px.php
     * does not), a reversal only where it reads status=reversed (pb.php and
     * gpb.php), and the event. Null for a row of no kind replay records.
     *
     * @param array<string, mixed> $row a log row as InboundLog shapes it
     * @return array{endpoint: string, amount: string|null, transaction: string, reversal: bool, reversal_ref: string,
     *     event: string|null, unread: list<string>}|null unread: what the request sent that this endpoint does not read
     */
    public static function plan(array $row): ?array
    {
        $endpoint = (string) ($row['endpoint'] ?? '');
        $kind = self::KINDS[$endpoint] ?? null;
        if ($kind === null) {
            return null;
        }
        $request = self::request($row['fields'] ?? null) ?? [];
        $text = static fn (string $name): string => is_string($request[$name] ?? null) ? trim($request[$name]) : '';
        $sentAmount = $text('amount') !== '' && is_numeric($text('amount')) ? $text('amount') : null;
        $sentTransaction = '';
        foreach (self::ORDER_NUMBER_NAMES as $name) {
            if ($sentTransaction === '') {
                $sentTransaction = $text($name);
            }
        }
        $sentReversal = strtolower($text('status')) === 'reversed';
        $unread = [];
        if ($sentAmount !== null && !$kind['amount']) {
            $unread[] = 'amount';
        }
        if ($sentTransaction !== '' && !$kind['txid']) {
            $unread[] = 'transaction id';
        }
        if ($sentReversal && !$kind['reversal']) {
            $unread[] = 'status=reversed';
        }

        return [
            'endpoint' => $endpoint,
            'amount' => $kind['amount'] ? $sentAmount : null,
            'transaction' => $kind['txid'] ? $sentTransaction : '',
            'reversal' => $kind['reversal'] && $sentReversal,
            'reversal_ref' => $kind['reversal'] ? $text('reversal_id') : '',
            'event' => $text('event') !== '' ? $text('event') : null,
            'unread' => $unread,
        ];
    }

    /**
     * Whether the click has a live sale under the transaction id, the row a
     * reversal or a negative amount takes money off: the writer's own target
     * (MysqlConversionRepository::record()), on the account's click.
     */
    public function saleOnFile(int $userId, int $clickId, string $transaction): bool
    {
        if ($transaction === '') {
            return false;
        }
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead(
            'SELECT deleted FROM 202_conversion_logs WHERE click_id = ? AND user_id = ? AND transaction_id = ?'
            . ' AND reverses_conv_id IS NULL ORDER BY conv_id LIMIT 1'
        );
        $conn->bind($stmt, 'iis', [$clickId, $userId, $transaction]);
        $row = $conn->fetchOne($stmt);

        return $row !== null && (int) $row['deleted'] === 0;
    }

    /** The click the row names, as write() records on; null when it names none. */
    public static function clickOf(array $row): ?int
    {
        if (!isset(self::KINDS[(string) ($row['endpoint'] ?? '')])) {
            return null;
        }

        return self::clickId($row, self::request($row['fields'] ?? null) ?? []);
    }

    /**
     * What the owner is asked before a refused request is recorded: what the
     * endpoint's recorder will do with it (plan()), a reversal as a reversal
     * and what becomes of it, when it arrived, that nothing vouched for it
     * when it carried no token, and which of the account's destinations will
     * not take it for its age.
     *
     * @param array<string, mixed> $row a log row as InboundLog shapes it
     * @param list<array<string, mixed>> $destinations the account's, as DestinationStore::list() gives them
     * @param array{first_visit: int|null, campaign_id: int, google_click: bool}|null $click the row's click (clickFacts())
     * @param bool|null $saleOnFile whether the transaction a reversal or negative amount names is on file (saleOnFile())
     */
    public static function question(array $row, array $destinations, int $now, ?array $click = null, ?bool $saleOnFile = null): string
    {
        $plan = self::plan($row);
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $named = $row['click_id'] ?? (is_string($fields['subid'] ?? null) && $fields['subid'] !== '' ? $fields['subid'] : null);
        $said = is_scalar($named) ? ['click ' . $named] : [];
        $takesMoney = false;
        $outcome = '';
        if ($plan !== null) {
            $transaction = $plan['transaction'];
            $negative = $plan['amount'] !== null && (float) $plan['amount'] < 0;
            if ($plan['reversal']) {
                $said[] = 'a reversal of transaction ' . ($transaction !== '' ? $transaction : '(none named)')
                    . ($plan['reversal_ref'] !== '' ? ' (reversal id ' . $plan['reversal_ref'] . ')' : '');
                if ($transaction === '') {
                    $outcome = ' It names no transaction to reverse, so it will be refused and nothing recorded.';
                } elseif ($saleOnFile === false) {
                    $outcome = ' No sale with transaction id ' . $transaction . ' is on file on this click, so it will be refused and nothing recorded.';
                } else {
                    $takesMoney = true;
                }
            } elseif ($negative && $transaction !== '' && $saleOnFile !== false) {
                $said[] = 'amount ' . $plan['amount'] . ', a reversal of transaction ' . $transaction;
                $takesMoney = true;
            } else {
                $said[] = $plan['amount'] !== null ? 'amount ' . $plan['amount'] : 'the campaign\'s payout';
                if ($transaction !== '') {
                    $said[] = 'transaction id ' . $transaction;
                }
                if ($negative) {
                    $outcome = ' No sale ' . ($transaction !== '' ? 'with transaction id ' . $transaction . ' ' : '')
                        . 'is on file to reverse, so it is recorded as a negative correction of ' . $plan['amount'] . '.';
                }
            }
            if ($plan['event'] !== null) {
                $said[] = 'event ' . $plan['event'];
            }
            if ($plan['unread'] !== []) {
                $outcome .= ' ' . $plan['endpoint'] . '.php does not read its ' . implode(' or ', $plan['unread']) . ', so it is not used.';
            }
        }

        $arrived = (int) ($row['received_at'] ?? 0);

        return ($takesMoney ? 'Record this reversal now? It takes money off a sale already recorded. ' : 'Record this request now? ')
            . 'It says: ' . implode(', ', $said) . '.' . $outcome . ' '
            . (in_array($row['auth'] ?? null, ['none', 'invalid'], true)
                ? 'It did not carry your postback token, so nothing shows who sent it: record it only if you know it is real. '
                : '')
            . 'It is recorded as it would have been when it arrived, ' . date('M j, Y g:i a', $arrived) . '.'
            . self::tooOldFor($destinations, $arrived, $now, $click);
    }

    /**
     * The sentence naming the account's switched-on destinations that will
     * not take this conversion for its age ('' when none), each judged as it
     * judges a conversion: Google Analytics by the conversion's own time
     * (Ga4Destination::MAX_AGE_SECONDS), as ChatGPT Ads is
     * (OpenAiAdsDestination::MAX_AGE_SECONDS), Google Ads by its click's first
     * visit, when the click carries an id Google matches it by
     * (GoogleAdsDestination::CLICK_MAX_AGE). A destination that sends only
     * some campaigns is named only for one of them.
     *
     * @param list<array<string, mixed>> $destinations
     * @param array{first_visit: int|null, campaign_id: int, google_click: bool}|null $click as clickFacts() reads it
     */
    public static function tooOldFor(array $destinations, int $arrived, int $now, ?array $click = null): string
    {
        $analytics = [];
        $ads = [];
        $chat = [];
        foreach ($destinations as $destination) {
            if (($destination['enabled'] ?? false) !== true) {
                continue;
            }
            $settings = (array) ($destination['settings'] ?? []);
            $campaigns = is_array($settings['campaign_ids'] ?? null) ? $settings['campaign_ids'] : [];
            if ($campaigns !== [] && ($click === null || !in_array($click['campaign_id'], $campaigns, true))) {
                continue;
            }
            $name = (string) ($destination['name'] ?? '');
            if (($destination['type'] ?? '') === Ga4Destination::TYPE && $now - $arrived > Ga4Destination::MAX_AGE_SECONDS) {
                $analytics[] = $name;
            }
            $type = (string) ($destination['type'] ?? '');
            if ($type === OpenAiAdsDestination::TYPE && $now - $arrived > OpenAiAdsDestination::MAX_AGE_SECONDS) {
                $chat[] = $name;
            }
            if (($destination['type'] ?? '') === GoogleAdsDestination::TYPE && $click !== null && $click['google_click']
                && $now - ($click['first_visit'] ?? $arrived) > GoogleAdsDestination::CLICK_MAX_AGE) {
                $ads[] = $name;
            }
        }
        $said = '';
        if ($analytics !== []) {
            $said .= ' Google Analytics takes conversions up to ' . intdiv(Ga4Destination::MAX_AGE_SECONDS, 3600) . ' hours old, so '
                . implode(', ', $analytics) . (count($analytics) === 1 ? ' does' : ' do') . ' not receive this one.';
        }
        if ($chat !== []) {
            $said .= ' ChatGPT Ads takes conversions up to ' . intdiv(OpenAiAdsDestination::MAX_AGE_SECONDS, 86400)
                . ' days old, so ' . implode(', ', $chat) . (count($chat) === 1 ? ' does' : ' do')
                . ' not receive this one.';
        }
        if ($ads !== []) {
            $said .= ' Google Ads takes a conversion up to ' . intdiv(GoogleAdsDestination::CLICK_MAX_AGE, 86400) . ' days after its'
                . ' click, and this click is older, so ' . implode(', ', $ads) . (count($ads) === 1 ? ' does' : ' do') . ' not receive it.';
        }

        return $said === '' ? '' : $said . ' Your reports still count it.';
    }

    /**
     * The row's click as the destinations judge it, or null when the row
     * names none of the account's clicks: its first visit's time and its
     * Google click ids (the visit and ids DestinationQueue sends), and the
     * campaign its conversion would credit (its latest visit's).
     *
     * @param array<string, mixed> $row
     * @return array{first_visit: int|null, campaign_id: int, google_click: bool}|null
     */
    public function clickFacts(int $userId, array $row): ?array
    {
        $request = self::request($row['fields'] ?? null) ?? [];
        $kind = self::KINDS[(string) ($row['endpoint'] ?? '')] ?? null;
        $clickId = $kind === null ? null : self::clickId($row, $request);
        if ($clickId === null) {
            return null;
        }
        $conn = new Connection($this->db);
        $stmt = $conn->prepareRead('SELECT c.click_time FROM ' . FirstVisit::of('?') . ' AS c WHERE c.user_id = ?');
        $conn->bind($stmt, 'ii', [$clickId, $userId]);
        $first = $conn->fetchOne($stmt);
        if ($first === null) {
            return null;
        }
        $stmt = $conn->prepareRead('SELECT c.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c WHERE c.user_id = ?');
        $conn->bind($stmt, 'ii', [$clickId, $userId]);
        $latest = $conn->fetchOne($stmt);
        $tokens = TrafficSourcePixels::clickTokens($conn, $userId, $clickId)['tokens'] ?? [];
        $google = false;
        foreach (GoogleAdsDestination::CLICK_IDS as $type) {
            $google = $google || trim((string) ($tokens[$type] ?? '')) !== '';
        }

        return [
            'first_visit' => $first['click_time'] === null ? null : (int) $first['click_time'],
            'campaign_id' => (int) ($latest['aff_campaign_id'] ?? 0),
            'google_click' => $google,
        ];
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
        // The request's consent as the log kept it: '1' given, '0' refused
        // (Redactor). A row logged before the log kept it holds '[redacted]',
        // which is not 1, so it reads as a refusal: an answer that cannot be
        // read is never consent (CLAUDE.md #11). Carried even then, never
        // dropped, since an absent p202_consent reads as consent given (#12).
        if (array_key_exists('p202_consent', $fields)) {
            $consent = $fields['p202_consent'];
            $request['p202_consent'] = is_string($consent) ? $consent : Redactor::REDACTED;
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
                    $now,
                    $this->clickFacts($userId, $row)
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

        // What the endpoint reads of the request, as the question before recording described it.
        $plan = self::plan($row) ?? throw new \LogicException('Inbound log: request ' . (int) $row['inbound_id'] . ' has no recorder');
        $outcome = p202RecordLegacyConversion($this->db, $clickId, $kind['pixel_type'], $matchedBy, [
            'ip' => self::NO_VISITOR,
            'user_agent' => self::USER_AGENT,
            'transaction_id' => $plan['transaction'],
            'source' => $kind['source'],
            // Dated when the sender reported it, so it counts on the day it happened.
            'conv_time' => (int) $row['received_at'],
            'notify_traffic_source' => $kind['notify'],
            'use_pixel_payout' => $plan['amount'] !== null,
            'payout' => $plan['amount'] ?? '0',
            'items' => $kind['items'] ? p202ExtractItems($request) : [],
            'event_name' => $event['event_name'] ?? null,
            'reversal' => $plan['reversal'],
            'reversal_ref' => $plan['reversal_ref'],
            // As the endpoint read it; a value the log redacted is no 1, so it withholds.
            'consent_withheld' => !\Prosper202\Identity\RequestSignals::consentGiven($request),
        ] + $scope);

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
