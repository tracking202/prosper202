<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\AmountOutOfRange;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Database\Connection;

/**
 * "Record it now": the conversion of a server postback the log kept and the
 * install refused, recorded on the owner's say-so (Setup › Inbound Log, POST
 * /conversions/inbound-log/{id}/record).
 *
 * The request is rebuilt from the row's kept fields and handed to
 * p202RecordLegacyConversion(), the recorder pb.php calls: the click must be
 * the account's, a pb row's click must have visited the campaign it names,
 * a transaction id de-duplicates, and without one a click converts once.
 * Only what the owner can vouch for is offered: a pb or gpb request refused
 * for its credentials or by an error here, whose every kept value is whole.
 *
 * Pressing twice records once. The row is taken first (claimReplay()), so
 * one caller writes; and the recorder itself answers a second attempt as a
 * duplicate (a transaction id) or as already converted (none), so a row
 * given back after an error is safe to ask again.
 */
final class InboundReplay
{
    public const ENDPOINTS = ['pb', 'gpb'];
    public const OUTCOMES = ['unauthenticated', 'failed'];
    public const USER_AGENT = 'Prosper202 Inbound Log';
    /** No visitor sends a server postback, and the sender's address is not kept. */
    private const NO_VISITOR = '';

    public function __construct(private readonly \mysqli $db)
    {
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
        if (($row['conversion_id'] ?? null) !== null) {
            return 'This request has its conversion already.';
        }
        if (!in_array($row['endpoint'] ?? null, self::ENDPOINTS, true)) {
            return 'Only a server postback (pb.php or gpb.php) can be recorded from the log. A pixel\'s click came from the'
                . ' visitor\'s browser and a payment notification\'s from its signed body, and the log keeps neither.';
        }
        if (!in_array($row['outcome'] ?? null, self::OUTCOMES, true)) {
            return 'Recording it again would be refused for the same reason.';
        }
        if (($row['method'] ?? '') !== 'GET') {
            return 'It was sent as a form post, and the log keeps only what the URL carried.';
        }
        $request = self::request($row['fields'] ?? null);
        if ($request === null) {
            return 'The log did not keep the whole request, so recording from it could record something else.';
        }
        if (trim((string) ($request['event'] ?? '')) !== '') {
            return 'It carries an event, which the campaign\'s goals evaluate as it arrives. Ask the sender to send it again.';
        }
        if (self::clickId((string) $row['endpoint'], $request) === null) {
            return 'Its subid is not a plain click id, so the log cannot say which click it names.';
        }
        if ($row['endpoint'] === 'pb' && ClickId::parse($request['acip'] ?? null) === null) {
            return 'It names no campaign (acip), which a pb.php postback is recorded on.';
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

    /** @param array<string, string> $request */
    private static function clickId(string $endpoint, array $request): ?int
    {
        return ClickId::parse($request['subid'] ?? null)
            ?? ($endpoint === 'gpb' ? ClickId::parse($request['sid'] ?? null) : null);
    }

    /**
     * @return array{status: string, message: string, conversion_id: int|null, click_id: int|null}
     *         status: recorded, duplicate (on file already under its transaction id), already_recorded,
     *         in_progress, refused (the recorder's own refusal), not_recordable, not_found or failed
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
            return self::answer(
                'already_recorded',
                'This request was recorded already, as conversion ' . $row['conversion_id'] . '.',
                $row['conversion_id'],
                $row['click_id']
            );
        }
        $refusal = self::refusalOf($row);
        if ($refusal !== null) {
            return self::answer('not_recordable', $refusal);
        }
        if (!$log->claimReplay($userId, $inboundId, $now)) {
            $again = $log->find($userId, $inboundId);
            if ($again !== null && $again['conversion_id'] !== null) {
                return self::answer(
                    'already_recorded',
                    'This request was recorded already, as conversion ' . $again['conversion_id'] . '.',
                    $again['conversion_id'],
                    $again['click_id']
                );
            }

            return self::answer('in_progress', 'This request is being recorded right now. Reload the log in a moment.');
        }

        $request = self::request($row['fields']) ?? [];
        $clickId = self::clickId((string) $row['endpoint'], $request);
        try {
            $outcome = $this->write($userId, $row, $request, (int) $clickId);
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

            return self::answer('failed', 'It could not be recorded just now. Try again: a conversion that did get recorded'
                . ' is found, not recorded twice.', null, $clickId);
        }
        if (is_string($outcome)) {
            $log->releaseReplay($userId, $inboundId, $now);

            return self::answer('refused', $outcome, null, $clickId);
        }
        if ($outcome['conv_id'] <= 0) {
            $log->releaseReplay($userId, $inboundId, $now);
            $why = RequestLog::LEGACY_REASONS[$outcome['reason']] ?? 'The recorder refused it (' . $outcome['reason'] . ').';

            return self::answer('refused', 'Nothing was recorded. ' . $why, null, $clickId);
        }
        try {
            $log->finishReplay($userId, $inboundId, $outcome['conv_id'], $now);
        } catch (\Throwable $noteFailed) {
            // The conversion stands; only the log row's note of it is missing.
            error_log('Inbound log: request ' . $inboundId . ' was recorded as conversion ' . $outcome['conv_id']
                . ' and the log row could not be marked: ' . $noteFailed->getMessage());
        }

        return $outcome['recorded']
            ? self::answer('recorded', 'Recorded as conversion ' . $outcome['conv_id'] . ' on click ' . $clickId . '.', $outcome['conv_id'], $clickId)
            : self::answer('duplicate', 'A conversion with this transaction id was on file already (conversion ' . $outcome['conv_id']
                . '); nothing was added.', $outcome['conv_id'], $clickId);
    }

    /**
     * The recorder's answer, or the sentence that says why nothing reached it.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $request
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}|string
     */
    private function write(int $userId, array $row, array $request, int $clickId): array|string
    {
        require_once dirname(__DIR__) . '/static-endpoint-helpers.php';

        $opts = [
            // The click must be this account's, whatever the request named.
            'user_id' => $userId,
            'transaction_id' => p202ExtractTransactionId($request),
            'user_agent' => self::USER_AGENT,
            // Dated when the sender reported it, so it counts on the day it happened.
            'conv_time' => (int) $row['received_at'],
            'notify_traffic_source' => true,
        ] + p202ExtractReversal($request);

        if ($row['endpoint'] === 'pb') {
            $campaignId = $this->campaignOf($userId, (int) ClickId::parse($request['acip'] ?? null));
            if ($campaignId === null) {
                return 'Nothing was recorded. The campaign the request names (acip ' . $request['acip'] . ') is not one of your account\'s.';
            }
            $opts['campaign_id'] = $campaignId;
            $opts['source'] = ConversionSource::LEGACY_PIXEL->value;
        } else {
            $opts['source'] = ConversionSource::POSTBACK->value;
            if (array_key_exists('amount', $request) && is_numeric($request['amount'])) {
                $opts['use_pixel_payout'] = true;
                $opts['payout'] = $request['amount'];
            }
            $opts['items'] = p202ExtractItems($request);
        }

        return p202RecordLegacyConversion($this->db, $clickId, 2, MatchMethod::CLICK_ID, ['ip' => self::NO_VISITOR] + $opts);
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
