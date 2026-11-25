<?php

declare(strict_types=1);

use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;
use Prosper202\Conversion\PostbackAuth\PostbackAuthReadiness;
use Prosper202\Conversion\PostbackAuth\PostbackAuthStore;
use Prosper202\Database\Connection;

/**
 * Home's warning that the account accepts postbacks and pixels without its
 * token (mode off, which the 1.9.80 upgrade gives every existing account),
 * for a role that may change Account › API integrations; '' otherwise.
 *
 * A mode that cannot be read shows nothing (logged): the gate does not read
 * an unreadable mode as off either, and API integrations says it is
 * unreadable. A mode read as off whose readiness cannot be read still shows
 * the warning, without the counts, rather than nothing: the counts are what
 * failed, not the fact that the token is not required.
 */
function p202_postback_auth_home_notice(mixed $db, int $userId, bool $mayChange, string $base, ?int $now = null): string
{
    if (!$mayChange || !$db instanceof \mysqli || $userId <= 0) {
        return '';
    }
    $conn = new Connection($db);
    try {
        $mode = (new PostbackAuthStore($conn))->mode($userId);
    } catch (\Throwable $e) {
        error_log('Home: the postback authentication mode could not be read: ' . get_class($e) . ': '
            . $e->getMessage());

        return '';
    }
    if ($mode !== PostbackAuthMode::OFF) {
        return '';
    }
    $ready = null;
    try {
        $ready = (new PostbackAuthReadiness($conn))->forAccount($userId, $now ?? time());
    } catch (\Throwable $e) {
        error_log('Home: postback readiness could not be read: ' . get_class($e) . ': ' . $e->getMessage());
    }

    return p202_postback_auth_off_notice($mode, $ready, $base);
}

/**
 * The warning itself: '' unless $mode is off. $ready is
 * PostbackAuthReadiness::forAccount()'s answer, null when it could not be
 * read. "Safe to require" is said only as API integrations says it: every
 * request of the window was seen, and none came without the token.
 *
 * @param array{requests: int, unread: int, without_credentials: int, since: int, covers_since: int}|null $ready
 */
function p202_postback_auth_off_notice(PostbackAuthMode $mode, ?array $ready, string $base): string
{
    if ($mode !== PostbackAuthMode::OFF) {
        return '';
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $days = PostbackAuthReadiness::WINDOW_DAYS;

    if ($ready === null) {
        $state = 'unknown';
        $count = 'How many postbacks and pixels of the last ' . $days . ' days arrived without it'
            . ' could not be counted just now.';
    } else {
        $window = $ready['covers_since'] > $ready['since']
            ? 'since ' . date('M j, g:i a', $ready['covers_since'])
            : 'in the last ' . $days . ' days';
        $requests = $ready['requests'];
        $without = $ready['without_credentials'];
        if ($requests === 0) {
            $state = 'unknown';
            $count = 'No postback or pixel has arrived ' . $window
                . ', so there is nothing yet to check requiring it against.';
        } elseif ($without > 0) {
            $state = 'no';
            $count = number_format($without) . ' of the ' . number_format($requests) . ' that arrived ' . $window
                . ' came without it and would be refused once it is required.';
        } elseif ($ready['unread'] > 0) {
            $state = 'unknown';
            $count = 'None that arrived ' . $window . ' is known to have come without it, but for '
                . number_format($ready['unread']) . ' of ' . number_format($requests)
                . ' what they carried was not read.';
        } else {
            $state = 'yes';
            $count = ($requests === 1 ? 'The one that arrived ' : 'All ' . number_format($requests) . ' that arrived ')
                . $window . ' carried it or a signature, so it is safe to require.';
        }
    }

    $link = $state === 'no' ? 'See who sent them' : 'Require the token';

    return '<div class="alert alert-warning p202-flash" role="status" id="postback-auth-off"'
        . ' data-postback-ready="' . $state . '">'
        . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">'
        . '<strong>Your postbacks and pixels are accepted without your postback token.</strong>'
        . ' Anyone who guesses a click id can add conversions, which are then sent to your ad platforms. '
        . $e($count)
        . ' <a href="' . $e($base . '202-account/api-integrations.php#postback-auth') . '">'
        . $link . ' in Account › API integrations</a>'
        . '</div></div>';
}
