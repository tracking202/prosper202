<?php

declare(strict_types=1);

/**
 * Setup › Traffic Sources' ChatGPT Ads spend card: each ChatGPT Ads
 * connection with whether its spend is pulled into the spend ledger every
 * hour (off until turned on here, in `p202 ad-spend sync` or
 * PUT /api/v3/spend-ledger/sync/{id}), its last run, and how far the
 * ledger's spend reaches. Nothing when the account has no connection.
 */
function p202_spend_sync_card(mysqli $db, int $userId, string $base, string $token): string
{
    $conn = new \Prosper202\Database\Connection($db);
    try {
        $sync = new \Prosper202\Spend\OpenAi\SpendSync(
            $conn,
            new \Prosper202\Spend\OpenAi\AdConnectionSpendConnections(new \Prosper202\AdPlatforms\AdConnectionStore($conn))
        );
        $states = $sync->status($userId);
        $reaches = (new \Prosper202\Spend\SpendLedger($conn))->reaches($userId);
        $zone = new DateTimeZone(\Prosper202\Report\AccountZone::read($conn, $userId));
    } catch (\Throwable $e) {
        error_log('Traffic Sources: the ChatGPT Ads spend card could not be read: ' . $e->getMessage());

        return '<section class="p202-panel mt-4" id="chatgpt-spend"><div class="p202-panel__body">'
            . p202_flash('error', 'ChatGPT Ads spend sync could not be read just now; reload the page to try again.')
            . '</div></section>';
    }
    if ($states === []) {
        return '';
    }
    $at = static fn (?int $t): string => $t === null ? 'never' : (new DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d H:i');
    $action = $base . 'tracking202/setup/spend_sync.php';

    $rows = '';
    foreach ($states as $state) {
        $on = $state['enabled'] === true;
        $detail = $on
            ? 'Last run ' . $at($state['last_run_at']) . ', ' . (int) $state['last_rows'] . ' hour rows'
                . ($state['backfilled_at'] === null ? '; the first run reads the last 30 days' : '')
            : 'Off: reports cost these clicks at their CPC.';
        $rows .= '<li class="p202-list__item">'
            . '<span class="p202-list__name">' . p202_setup_e($state['account_ref']) . '</span>'
            . '<span class="p202-pill' . ($on ? ' p202-pill--accent' : '') . '">' . ($on ? 'Pulling spend hourly' : 'Off') . '</span>'
            . '<span class="p202-list__actions"><form method="post" action="' . p202_setup_e($action) . '" class="d-inline">'
            . p202_setup_token_field($token)
            . '<input type="hidden" name="connection_id" value="' . (int) $state['connection_id'] . '">'
            . '<input type="hidden" name="enabled" value="' . ($on ? '0' : '1') . '">'
            . '<button type="submit" class="p202-list__action">' . ($on ? 'turn off' : 'turn on') . '</button>'
            . '</form></span>'
            . '<div class="form-text w-100">' . p202_setup_e($detail) . '</div>'
            . ($state['last_error'] !== null ? '<div class="form-text text-danger w-100">' . p202_setup_e($state['last_error']) . '</div>' : '')
            . '</li>';
    }

    return '<section class="p202-panel mt-4" id="chatgpt-spend">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">ChatGPT Ads spend</h2>'
        . '<p class="p202-panel__sub">What OpenAI billed per ad per hour, pulled every hour into the spend ledger. Reports cost the clicks carrying each ad at its share of the hour\'s spend.</p></div>'
        . '<div class="p202-panel__body">'
        . '<p class="mb-2">' . ($reaches === null ? 'No spend data yet.' : 'Spend data reaches ' . p202_setup_e($at($reaches)) . '.') . '</p>'
        . '<ul class="p202-list">' . $rows . '</ul>'
        . '</div></section>';
}
