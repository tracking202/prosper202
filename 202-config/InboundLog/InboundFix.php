<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

/**
 * What to do about a request that recorded nothing: one sentence and, where
 * a page fixes it, that page (a path under the install's address). The
 * Inbound Log links it and GET /conversions/inbound-log answers it as `fix`.
 */
final class InboundFix
{
    private const POSTBACK_PAGE = 'tracking202/setup/get_postback.php';
    private const CAMPAIGNS_PAGE = 'tracking202/setup/aff_campaigns.php';
    private const INTEGRATIONS_PAGE = '202-account/api-integrations.php';
    private const CONVERSIONS_PAGE = 'tracking202/logs/';

    /** outcome => [what to do, page or null, the link's words or null] */
    private const BY_OUTCOME = [
        'unauthenticated' => [
            'Give the sender the URL or pixel from Setup › Postback / Pixel, which carries your token.'
                . ' Account › API Integrations › Postback authentication decides what a request must carry.',
            self::POSTBACK_PAGE, 'Copy the URL with your token',
        ],
        'unknown_campaign' => [
            'The acip in the URL names no campaign here. Copy the campaign\'s URL or pixel again.',
            self::POSTBACK_PAGE, 'Copy the campaign\'s URL',
        ],
        'unknown_click' => [
            'The sender returned a subid that is no click here. Check that the campaign\'s URL hands the network [[subid]],'
                . ' and that the network returns that value as subid.',
            self::CAMPAIGNS_PAGE, 'Check the campaign\'s URL',
        ],
        'no_click' => [
            'Nothing named a click: the visitor had no tracking cookie, or the notification carried no subid.'
                . ' Check that the campaign\'s URL hands the network [[subid]] and the network returns it.',
            self::CAMPAIGNS_PAGE, 'Check the campaign\'s URL',
        ],
        'malformed_click' => [
            'The subid is not a click id: the network returned an unfilled or altered value. Check which of its tokens the postback URL uses for subid.',
            self::POSTBACK_PAGE, 'Check the postback URL',
        ],
        'campaign_mismatch' => [
            'The click was on another campaign than the URL names. Give that campaign its own URL, or use the global postback, which takes every campaign.',
            self::POSTBACK_PAGE, 'Copy the right URL',
        ],
        'foreign_click' => [
            'The click belongs to another account than the campaign in the URL. Use the URL from the account that owns the click.',
            self::POSTBACK_PAGE, 'Copy the right URL',
        ],
        'already_lead' => [
            'The click had converted already and the request names no transaction id, so it reads as a retry.'
                . ' Add the network\'s order or transaction id to the URL (txid=) so each sale records.',
            self::POSTBACK_PAGE, 'See the URL\'s parameters',
        ],
        'amount_out_of_range' => [
            'The amount is more than a conversion can hold. Check which value the network puts in amount=.',
            self::POSTBACK_PAGE, 'Check the postback URL',
        ],
        'unverified' => [
            'The signature did not verify with the secret saved here. Save the same secret the sender signs with.',
            self::INTEGRATIONS_PAGE, 'Check the saved secret',
        ],
        'ambiguous_account' => [
            'More than one account here has saved the secret that verified this. Keep it on the one account it belongs to.',
            self::INTEGRATIONS_PAGE, 'Check the saved secret',
        ],
        'failed' => [
            'An error here stopped it; the server\'s error log has the reason. Nothing is wrong with the sender.',
            null, null,
        ],
        'upgrade_pending' => [
            'This install\'s database upgrade has not run yet. Run it, then ask the sender to send the notification again.',
            null, null,
        ],
        'invalid_event' => [
            'The event is malformed; the reason names the parameter. Correct it where the event is sent.',
            null, null,
        ],
        // A pixel's event refusals (px.php, gpx.php: 'event_' and the event recorder's status).
        'event_invalid' => [
            'The event is malformed; the reason names the parameter. Correct it where the pixel is placed.',
            null, null,
        ],
        'event_refused' => [
            'The event was refused: an event id sent again with other details, or an event that is also a reversal.'
                . ' Give each event its own event_id, and send a reversal as status=reversed with its sale\'s transaction id.',
            null, null,
        ],
        'event_not_found' => [
            'The event names a click that is not here, or not one of the campaign\'s. Check that the campaign\'s URL hands the'
                . ' network [[subid]] and the pixel returns it.',
            self::CAMPAIGNS_PAGE, 'Check the campaign\'s URL',
        ],
        // What an endpoint refused in its own words (the reason says which): a subid or ad click id naming no click of the account's.
        'refused' => [
            'The reason says what was refused. Most often the subid or the ad click id the network sent back names none of'
                . ' your clicks: check that the campaign\'s URL hands the network [[subid]] and that it returns that value.',
            self::CAMPAIGNS_PAGE, 'Check the campaign\'s URL',
        ],
        'not_recorded' => [
            'The click is on file and the conversion writer recorded nothing; the server\'s error log has the reason.'
                . ' Ask the sender to send it again once it is fixed.',
            null, null,
        ],
        // Payment notifications (OutcomeKind).
        'conflict' => [
            'This payment is on file already with another amount or customer, so it was not changed. Check the sale; if the'
                . ' recorded one is wrong, delete it and ask the provider to send the notification again.',
            self::CONVERSIONS_PAGE, 'Open Conversion Logs',
        ],
        'currency_mismatch' => [
            'The payment is in another currency than your account counts in, and is held. Account › API Integrations records'
                . ' the held sales once the account counts in their currency.',
            self::INTEGRATIONS_PAGE, 'Open API Integrations',
        ],
        'malformed' => [
            'The notification lacks a field the provider always sends, or one is malformed; the reason names it. Check the'
                . ' integration\'s settings at the provider.',
            self::INTEGRATIONS_PAGE, 'Check the integration',
        ],
        'method_not_allowed' => [
            'It was sent with a method this address does not take. Give the provider the URL shown for it on Account › API'
                . ' Integrations.',
            self::INTEGRATIONS_PAGE, 'Copy the integration\'s URL',
        ],
        'unsupported_body' => [
            'Another product\'s notification was sent to this address. Give the provider the URL for that product.',
            self::INTEGRATIONS_PAGE, 'Copy the integration\'s URL',
        ],
        'nothing_to_reverse' => [
            'A refund or chargeback for a sale that is not on file, so there was nothing to take back. If the sale should be'
                . ' here, find out why its own notification was refused.',
            self::CONVERSIONS_PAGE, 'Open Conversion Logs',
        ],
    ];

    /**
     * The outcomes that never need a fix line, with why: each other outcome
     * a refusal can carry has one (InboundFixCoversEveryRefusalTest).
     */
    public const NO_FIX = [
        'no_conversion' => 'the endpoint answered 2xx and noted no outcome of its own: its reason is the only account of'
            . ' it, and there is no one thing to do about it',
    ];

    /** @return list<string> the outcomes with a fix line */
    public static function outcomes(): array
    {
        return array_keys(self::BY_OUTCOME);
    }

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row a log row as InboundLog shapes it
     * @return array{text: string, page: string|null, label: string|null}|null null for a row with nothing to fix
     */
    public static function of(array $row): ?array
    {
        if (($row['accepted'] ?? false) === true) {
            return null;
        }
        $fix = self::BY_OUTCOME[(string) ($row['outcome'] ?? '')] ?? null;
        if ($fix === null) {
            return null;
        }

        return ['text' => $fix[0], 'page' => $fix[1], 'label' => $fix[2]];
    }
}
