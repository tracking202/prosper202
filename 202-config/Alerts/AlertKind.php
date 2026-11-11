<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/**
 * The money-loss alerts, each named by the webhook event it is queued as
 * (MysqlWebhookRepository::KNOWN_EVENTS lists every value). A new alert adds a
 * case and an AlertDetector.
 */
enum AlertKind: string
{
    case ConversionsStopped = 'alert.conversions_stopped';
    case SpendWithoutConversions = 'alert.spend_without_conversions';
    case PostbackRefusalsSpiked = 'alert.postback_refusals_spiked';
    /** The settings card's "Send a test alert": no detector raises it. */
    case Test = 'alert.test';

    /** The alerts a detector raises: what a webhook subscribes to to hear of a problem. */
    public const DETECTED = [self::ConversionsStopped, self::SpendWithoutConversions, self::PostbackRefusalsSpiked];

    /** The key 202_alert_state records the incident under. */
    public function stateKey(): string
    {
        return substr($this->value, strlen('alert.'));
    }

    public function title(): string
    {
        return match ($this) {
            self::ConversionsStopped => 'Conversions stopped',
            self::SpendWithoutConversions => 'Spend with no conversions',
            self::PostbackRefusalsSpiked => 'Postback refusals jumped',
            self::Test => 'Test alert',
        };
    }

    /**
     * The page that shows the problem, from the install's root: the inbound
     * log for conversions that stopped arriving or are refused, the
     * campaign for one that spends without converting.
     */
    public function page(int $campaignId): string
    {
        return match ($this) {
            self::ConversionsStopped => 'tracking202/setup/inbound_log.php',
            self::PostbackRefusalsSpiked => 'tracking202/setup/inbound_log.php?outcome=refused',
            self::SpendWithoutConversions => 'tracking202/setup/aff_campaigns.php?edit_aff_campaign_id=' . $campaignId,
            self::Test => AlertNotifier::SETTINGS_PAGE,
        };
    }

    public function pageLabel(): string
    {
        return match ($this) {
            self::ConversionsStopped => 'See what its postbacks and pixels last sent (Setup › Inbound Log)',
            self::PostbackRefusalsSpiked => 'See the refused requests and why (Setup › Inbound Log)',
            self::SpendWithoutConversions => 'Open the campaign (Setup › Campaigns)',
            self::Test => 'Open your alert settings',
        };
    }
}
