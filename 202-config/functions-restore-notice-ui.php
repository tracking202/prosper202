<?php

/**
 * Settings' notice of what a database restore set aside (the API state
 * written after the backup, ServerStateStore::restoreNotices()).
 */

declare(strict_types=1);

use Api\V3\Support\ServerStateStore;

const P202_RESTORE_NOTICE_KINDS = [
    'staged_changes' => ['staged change', 'staged changes'],
    'idempotency' => ['retry record (Idempotency-Key)', 'retry records (Idempotency-Key)'],
    'jobs' => ['sync job', 'sync jobs'],
    'manifests' => ['sync manifest', 'sync manifests'],
    'changes' => ['change-feed entry', 'change-feed entries'],
];

/**
 * The notices to show; none when the API state cannot be read, which the
 * log says and the API answers for itself.
 *
 * @return list<array{at: int, dir: string, kept_until: int, counts: array<string, int>}>
 */
function p202_restore_notices(\mysqli $db): array
{
    try {
        return ServerStateStore::forInstall($db)->restoreNotices();
    } catch (\Throwable $e) {
        error_log('Settings: the database-restore notice could not be read: ' . $e->getMessage());

        return [];
    }
}

/** The Dismiss button's POST: the notices are not shown again; nothing set aside is touched. */
function p202_restore_notice_dismiss_posted(\mysqli $db): never
{
    try {
        ServerStateStore::forInstall($db)->dismissRestoreNotices();
    } catch (\Throwable $e) {
        error_log('Settings: the database-restore notice could not be dismissed: ' . $e->getMessage());
        p202_account_flash('bad', 'The notice could not be dismissed just now. Try again.');
    }
    p202_account_redirect('202-account/administration.php');
}

/** @param array<string, int> $counts */
function p202_restore_notice_counts(array $counts): string
{
    $parts = [];
    foreach (P202_RESTORE_NOTICE_KINDS as $kind => [$one, $many]) {
        $n = $counts[$kind] ?? 0;
        if ($n > 0) {
            $parts[] = number_format($n) . ' ' . ($n === 1 ? $one : $many);
        }
    }
    $other = array_sum(array_diff_key($counts, P202_RESTORE_NOTICE_KINDS));
    if ($other > 0) {
        $parts[] = number_format($other) . ' other ' . ($other === 1 ? 'record' : 'records');
    }

    return implode(', ', $parts);
}

/**
 * @param list<array{at: int, dir: string, kept_until: int, counts: array<string, int>}> $notices
 */
function p202_restore_notice(array $notices, string $self, string $token): string
{
    if ($notices === []) {
        return '';
    }
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $staged = 0;
    $lines = '';
    foreach ($notices as $notice) {
        $staged += $notice['counts']['staged_changes'] ?? 0;
        $lines .= '<li>' . $h(date('M j, Y H:i', $notice['at'])) . ': '
            . $h(p202_restore_notice_counts($notice['counts'])) . '. Kept in <code>' . $h($notice['dir'])
            . '</code> on the server until ' . $h(date('M j, Y', $notice['kept_until'])) . ', then removed.</li>';
    }

    return '<div class="alert alert-warning p202-flash" role="status" id="restore-notice">'
        . '<i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">'
        . '<strong>This database was restored from an earlier backup.</strong>'
        . ' What the API recorded after that backup describes data the database no longer has,'
        . ' so it was set aside and is not used:'
        . '<ul class="mb-2">' . $lines . '</ul>'
        . ($staged > 0
            ? '<p class="mb-2">Staged changes proposed after the backup are no longer listed. Propose them again'
                . ' if you still want them.</p>'
            : '')
        . '<p class="mb-2">A create sent again under an Idempotency-Key from after the backup is made again,'
        . ' and a client that reads the change feed should sync again from the start.</p>'
        . '<form method="post" action="' . $h($self) . '">'
        . '<input type="hidden" name="token" value="' . $h($token) . '">'
        . '<button class="btn btn-secondary btn-sm" type="submit" name="restore_notice_dismiss" value="1">'
        . 'Dismiss</button></form>'
        . '</div></div>';
}
