<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/** How the alerts write durations, multiples and money. */
final class AlertText
{
    private function __construct()
    {
    }

    /** 45 min, 3 h 20 min, 2 days 4 h. */
    public static function duration(int $seconds): string
    {
        $minutes = max(1, intdiv($seconds, 60));
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 48) {
            $rest = $minutes % 60;

            return $hours . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
        }
        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return $days . ' days' . ($rest > 0 ? ' ' . $rest . ' h' : '');
    }

    /** A multiple to one decimal below 10, whole above: 4.6, 12. */
    public static function times(float $multiple): string
    {
        return number_format($multiple, $multiple < 10 ? 1 : 0);
    }

    public static function money(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }
}
