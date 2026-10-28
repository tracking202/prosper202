<?php

declare(strict_types=1);

namespace Prosper202\Cron;

/**
 * What Account › Settings says about the AutoCron service's answer to a
 * register or deregister call (callAutoCron(), the decoded JSON or null).
 *
 * The service refuses an address it will not call -- localhost, a private or
 * other unreachable address, an IP literal -- with
 * {"status":"fail","data":{"code":"402",...}}, and the page said that as "the
 * AutoCron service could not be reached ... try again": the service was
 * reached, and trying again changes nothing.
 */
final class AutoCronAnswer
{
    public const REFUSED_ADDRESS = 'The AutoCron service refused this install\'s address: AutoCron works only for'
        . ' installs at a public web address. The setting is unchanged. Use a cron job on this server instead,'
        . ' one that runs 202-cronjobs/index.php every minute.';

    public const TURNED_AWAY = 'The AutoCron service turned the change away just now, so the setting is unchanged.'
        . ' Try again in a few minutes.';

    public const UNREACHABLE = 'The AutoCron service could not be reached, so the setting is unchanged.'
        . ' Try again in a few minutes.';

    /** Null when the service accepted the change, otherwise the sentence the page shows. */
    public static function refusal(mixed $answer): ?string
    {
        if (!is_array($answer)) {
            return self::UNREACHABLE;
        }
        if (($answer['status'] ?? null) === 'success') {
            return null;
        }
        $data = $answer['data'] ?? null;
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        if ((is_string($code) || is_int($code)) && (string) $code === '402') {
            return self::REFUSED_ADDRESS;
        }

        return self::TURNED_AWAY;
    }
}
