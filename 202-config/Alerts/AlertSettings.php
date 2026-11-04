<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/**
 * An account's money-loss alerts (202_alert_settings). On for an account with
 * no row: email to its own address, Slack only where it set a webhook, and
 * webhooks only where one subscribed to the alerts' events. Turning them off
 * stores a row with enabled 0.
 *
 * A campaign is steady when live conversions arrived in at least
 * minConversions distinct hours of the last LOOKBACK_DAYS; its usual gap is
 * the time between its first and last converting hour over the gaps between
 * (ConversionsStoppedDetector). dailyCostCap 0 is no cap.
 */
final class AlertSettings
{
    public const LOOKBACK_DAYS = 7;

    public const GAP_MULTIPLE_MIN = 2;
    public const GAP_MULTIPLE_MAX = 20;
    public const MIN_CONVERSIONS_MIN = 3;
    public const MIN_CONVERSIONS_MAX = 1000;
    public const COST_CAP_MAX = 9999999.99;

    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $notifySlack = true,
        public readonly bool $notifyEmail = true,
        public readonly bool $notifyWebhook = true,
        public readonly int $gapMultiple = 5,
        public readonly int $minConversions = 10,
        public readonly string $dailyCostCap = '0.00',
    ) {
        if ($gapMultiple < self::GAP_MULTIPLE_MIN || $gapMultiple > self::GAP_MULTIPLE_MAX) {
            throw new \InvalidArgumentException('Not a gap multiple: ' . $gapMultiple);
        }
        if ($minConversions < self::MIN_CONVERSIONS_MIN || $minConversions > self::MIN_CONVERSIONS_MAX) {
            throw new \InvalidArgumentException('Not a minimum of conversions: ' . $minConversions);
        }
        if (preg_match('/\A[0-9]{1,7}\.[0-9]{2}\z/', $dailyCostCap) !== 1) {
            throw new \InvalidArgumentException('Not a daily cost cap: ' . $dailyCostCap);
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function hasCostCap(): bool
    {
        return (float) $this->dailyCostCap > 0;
    }

    public function anyChannel(): bool
    {
        return $this->notifySlack || $this->notifyEmail || $this->notifyWebhook;
    }

    /**
     * The settings a form or a request asks for, or the reasons by field. A
     * switch is '1' or '0'; anything else, a list included, is refused.
     *
     * @param array<string, mixed> $input enabled, notify_slack, notify_email, notify_webhook,
     *        gap_multiple, min_conversions, daily_cost_cap
     * @return array{0: ?self, 1: array<string, string>}
     */
    public static function fromInput(array $input): array
    {
        $errors = [];
        $switches = [];
        foreach (['enabled', 'notify_slack', 'notify_email', 'notify_webhook'] as $name) {
            $value = $input[$name] ?? null;
            if ($value !== '0' && $value !== '1') {
                $errors[$name] = 'must be on or off';
                continue;
            }
            $switches[$name] = $value === '1';
        }

        $gap = self::integer($input['gap_multiple'] ?? null, self::GAP_MULTIPLE_MIN, self::GAP_MULTIPLE_MAX);
        if ($gap === null) {
            $errors['gap_multiple'] = 'must be a whole number from ' . self::GAP_MULTIPLE_MIN
                . ' to ' . self::GAP_MULTIPLE_MAX;
        }
        $min = self::integer($input['min_conversions'] ?? null, self::MIN_CONVERSIONS_MIN, self::MIN_CONVERSIONS_MAX);
        if ($min === null) {
            $errors['min_conversions'] = 'must be a whole number from ' . self::MIN_CONVERSIONS_MIN
                . ' to ' . self::MIN_CONVERSIONS_MAX;
        }

        $cap = $input['daily_cost_cap'] ?? null;
        $cap = is_string($cap) ? trim($cap) : $cap;
        if ($cap === '') {
            $cap = '0';
        }
        if (!is_string($cap) || preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z/', $cap) !== 1) {
            $errors['daily_cost_cap'] = 'must be an amount from 0 to ' . number_format(self::COST_CAP_MAX, 2, '.', '')
                . ' with at most two decimals (0 is no cap)';
            $cap = null;
        } else {
            $cap = number_format((float) $cap, 2, '.', '');
        }

        if (
            $errors === [] && $switches['enabled']
            && !$switches['notify_slack'] && !$switches['notify_email'] && !$switches['notify_webhook']
        ) {
            $errors['notify_email'] = 'cannot be off with Slack and webhooks off too while alerts are on:'
                . ' choose where they go';
        }
        if ($errors !== [] || $gap === null || $min === null || $cap === null) {
            return [null, $errors];
        }

        return [new self(
            $switches['enabled'],
            $switches['notify_slack'],
            $switches['notify_email'],
            $switches['notify_webhook'],
            $gap,
            $min,
            $cap,
        ), []];
    }

    private static function integer(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/\A[0-9]{1,5}\z/', trim($value)) !== 1) {
            return null;
        }
        $number = (int) trim($value);

        return $number >= $min && $number <= $max ? $number : null;
    }

    /**
     * @return array{enabled: bool, notify_slack: bool, notify_email: bool, notify_webhook: bool,
     *     gap_multiple: int, min_conversions: int, daily_cost_cap: string}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'notify_slack' => $this->notifySlack,
            'notify_email' => $this->notifyEmail,
            'notify_webhook' => $this->notifyWebhook,
            'gap_multiple' => $this->gapMultiple,
            'min_conversions' => $this->minConversions,
            'daily_cost_cap' => $this->dailyCostCap,
        ];
    }
}
