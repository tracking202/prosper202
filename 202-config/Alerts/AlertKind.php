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
        };
    }
}
