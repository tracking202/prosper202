<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

require_once __DIR__ . '/_includes/setup_ui.php';

use Api\V3\Controllers\InboundLogController;
use Api\V3\Exception\ValidationException;
use Prosper202\Http\PostedId;
use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Shopify\RefundsToSettle;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Prosper202\PaymentNotification\Shopify\WebhookStatus;

/*
 * Setup › Inbound Log: the postbacks, pixels and payment notifications this
 * install answered for the account, newest first, and why each one that
 * recorded nothing was refused. The same list as GET
 * /api/v3/conversions/inbound-log; secrets and visitor details are never kept.
 * Above it, the Shopify refunds that are not recorded while their order's
 * sale is (RefundsToSettle), each with a form to record or close it.
 */

$userId = (int) $_SESSION['user_id'];
$base = get_absolute_url();
$self = $base . 'tracking202/setup/inbound_log.php';
$seesUnattributed = $userObj->hasPermission('add_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['bad', 'That form has expired. Reload the page and try again; nothing was changed.'];
    if (AUTH::check_csrf_token()) {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $refundId = PostedId::read($_POST['refund_id'] ?? null);
        $flash = ['bad', 'That is not a refund this page lists; nothing was changed.'];
        try {
            $desk = new RefundsToSettle($db);
            if ($refundId !== null && $action === 'record_refund') {
                $postedClick = $_POST['click_id'] ?? null;
                $amount = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
                $outcome = $desk->settle(
                    $userId,
                    (string) $refundId,
                    $amount,
                    $postedClick === null || $postedClick === '' ? null : PostedId::read($postedClick),
                    new InboundRequest('POST', '', [], '', '', 'Prosper202 Setup › Inbound Log')
                );
                $recorded = in_array($outcome->kind, [OutcomeKind::REVERSED, OutcomeKind::DUPLICATE], true);
                $flash = [$recorded ? 'ok' : 'bad', 'Refund ' . $refundId . ': ' . $outcome->message];
            } elseif ($refundId !== null && $action === 'close_refund') {
                $flash = $desk->close($userId, (string) $refundId)
                    ? ['ok', 'Refund ' . $refundId . ' was closed with nothing recorded.']
                    : ['bad', 'Refund ' . $refundId . ' was recorded or closed already; nothing was changed.'];
            }
        } catch (\Throwable $e) {
            error_log(p202UncaughtLogLine('Setup › Inbound Log: a Shopify refund could not be settled', $e));
            $flash = ['bad', 'That could not be done just now, and the refund is still listed. If it keeps failing,'
                . ' the server\'s error log says why.'];
        }
    }
    $_SESSION['p202_inbound_log_flash'] = $flash;
    header('location: ' . $self . '#shopify-refunds');
    die();
}
$flash = $_SESSION['p202_inbound_log_flash'] ?? null;
unset($_SESSION['p202_inbound_log_flash']);

$params = array_intersect_key($_GET, array_flip(['outcome', 'endpoint', 'before']));
$params = array_filter($params, static fn (mixed $v): bool => is_string($v) && $v !== '');
$rows = [];
$errors = [];
$limit = 100;
try {
    $filters = InboundLogController::filters($params);
    // An account that stores a Shopify secret sees the webhooks no secret verified: one may be its own.
    $conn = new \Prosper202\Database\Connection($db);
    $stmt = $conn->prepareRead(
        'SELECT ' . ShopifyWebhookSecret::COLUMN . ' AS secret FROM 202_users_pref WHERE user_id = ? LIMIT 1'
    );
    $conn->bind($stmt, 'i', [$userId]);
    $shopifySecret = $conn->fetchOne($stmt)['secret'] ?? null;
    $storesSecret = ShopifyWebhookSecret::stored(is_string($shopifySecret) ? $shopifySecret : null) !== null;
    $rows = (new InboundLog($db))->list($userId, $seesUnattributed, $filters, $limit, $storesSecret);
} catch (ValidationException $e) {
    $errors = $e->getFieldErrors();
} catch (\Throwable $e) {
    error_log(p202UncaughtLogLine('Setup › Inbound Log: the log could not be read', $e));
    $errors['log'] = 'The log could not be read just now. If it keeps failing, the server\'s error log says why (an upgrade may be pending).';
}
$toSettle = ['refunds' => [], 'more' => false, 'transaction_webhook_seen' => true];
try {
    $toSettle = (new RefundsToSettle($db))->list($userId);
} catch (\Throwable $e) {
    error_log(p202UncaughtLogLine('Setup › Inbound Log: the Shopify refunds could not be read', $e));
    $errors['shopify_refunds'] = 'The Shopify refunds that are not recorded could not be read just now. If it keeps'
        . ' failing, the server\'s error log says why (an upgrade may be pending).';
}
$older = count($rows) === $limit ? (int) $rows[$limit - 1]['inbound_id'] : null;

$endpointLabels = [
    'pb' => 'Postback (pb.php)',
    'gpb' => 'Global postback (gpb.php)',
    'px' => 'Pixel (px.php)',
    'gpx' => 'Global pixel (gpx.php)',
    'upx' => 'Universal pixel (upx.php)',
    'clickbank' => 'ClickBank INS',
    'jvzoo' => 'JVZoo IPN',
    'paykickstart' => 'PayKickstart IPN',
    'shopify' => 'Shopify webhook',
    'zaxaa' => 'Zaxaa IPN',
];
$outcomeChoices = ['' => 'Everything', 'refused' => 'Refused or nothing recorded', 'accepted' => 'Accepted'];
$endpointChoices = ['' => 'Every endpoint'] + $endpointLabels;
$when = static fn (int $ts): string => date('Y-m-d H:i:s', $ts);

AUTH::set_timezone($_SESSION['user_timezone'] ?? date_default_timezone_get());
template_top('Inbound Log');
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-inbox"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Inbound Log</h1>
        <p class="p202-page-header__desc">Every postback, pixel and payment notification this install answered, and why one that recorded nothing was refused. The newest <?php echo number_format(InboundLog::KEEP_PER_ACCOUNT); ?> that recorded something are kept, and the newest <?php echo number_format(InboundLog::KEEP_REFUSED); ?> others; signatures, keys, addresses and buyers' details never are.</p>
    </div>
</div>

<?php
echo p202_setup_error_flashes($errors, ['outcome', 'endpoint', 'before']);
if (is_array($flash) && is_string($flash[0] ?? null) && is_string($flash[1] ?? null)) {
    echo p202_flash($flash[0], $flash[1]);
}
if ($seesUnattributed) {
    echo p202_flash('info', 'As an admin you also see the requests no account could be found for (an unknown campaign, a signature no stored key verifies); they show "—" as the account.');
} elseif (array_filter($rows, static fn (array $row): bool => $row['user_id'] === null) !== []) {
    echo p202_flash('info', 'The Shopify webhooks no stored secret verified are listed too, as account "—": if one came from your store, the secret saved under Account › API integrations › Shopify is not the one Shopify signs with.');
}
?>

<?php if ($toSettle['refunds'] !== []) {
    $waiting = count(array_filter($toSettle['refunds'], static fn (array $r): bool => $r['waiting']));
    $stale = count(array_filter($toSettle['refunds'], static fn (array $r): bool => $r['stale']));
    $token = (string) ($_SESSION['token'] ?? '');
    ?>
<section class="p202-panel mb-4" id="shopify-refunds">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Shopify refunds not recorded</h2>
        <span class="p202-pill p202-pill--warn"><?php echo count($toSettle['refunds']) . ($toSettle['more'] ? '+' : ''); ?></span>
    </div>
    <div class="p202-panel__body">
        <p class="text-body-secondary">Each of these refunds is still counted in its order’s sale. A waiting refund is recorded when Shopify says its transaction settled; a refused one is not tried again. Check each in Shopify, then record what it returned, or close it if no money went back.</p>
        <?php
        if ($stale > 0 && !$toSettle['transaction_webhook_seen']) {
            echo p202_flash('warn', 'Shopify’s Transaction creation webhook has never arrived for this account, and ' . ($stale === 1 ? 'a refund has' : $stale . ' refunds have') . ' waited more than a day. Without that webhook a refund that starts as pending is never settled here: in Shopify, under Settings › Notifications › Webhooks, add Transaction creation to the same URL as your order webhooks, and record the waiting refunds below.');
        } elseif ($stale > 0) {
            echo p202_flash('warn', ($stale === 1 ? 'A refund has' : $stale . ' refunds have') . ' waited more than a day for Shopify to say the transaction settled. Check ' . ($stale === 1 ? 'it' : 'them') . ' in Shopify and record or close ' . ($stale === 1 ? 'it' : 'them') . ' below.');
        }
        if ($toSettle['more']) {
            echo p202_flash('info', 'The oldest ' . RefundsToSettle::LIMIT . ' are listed; the rest show as these are settled.');
        }
        ?>
        <div class="p202-table-wrap">
            <table class="table p202-table" id="shopify-refunds-table">
                <thead>
                    <tr><th>Refunded</th><th>Order</th><th>Refund</th><th class="num">Refund states</th><th>Why it is not recorded</th><th>Settle</th></tr>
                </thead>
                <tbody>
                <?php foreach ($toSettle['refunds'] as $refund) {
                    $formId = 'refund-' . $refund['refund_id'];
                    ?>
                    <tr class="<?php echo $refund['stale'] || !$refund['waiting'] ? 'table-warning' : ''; ?>">
                        <td><?php echo $refund['occurred_at'] > 0 ? p202_setup_e($when($refund['occurred_at']) . ' (' . WebhookStatus::ago($refund['occurred_at']) . ')') : '—'; ?></td>
                        <td><?php echo p202_setup_e($refund['order_id']); ?></td>
                        <td><?php echo p202_setup_e($refund['refund_id']); ?></td>
                        <td class="num"><?php echo p202_setup_e(trim($refund['amount'] . ' ' . $refund['currency'])); ?></td>
                        <td><?php echo p202_setup_e($refund['waiting'] ? 'Waiting for Shopify to say its transaction settled.' : $refund['reason']); ?></td>
                        <td>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>" class="d-flex flex-wrap gap-2 align-items-center mb-2" id="<?php echo p202_setup_e($formId); ?>">
                                <?php echo p202_setup_token_field($token); ?>
                                <input type="hidden" name="action" value="record_refund">
                                <input type="hidden" name="refund_id" value="<?php echo p202_setup_e($refund['refund_id']); ?>">
                                <input type="text" inputmode="decimal" class="form-control form-control-sm w-auto" name="amount" size="9" required
                                    aria-label="Amount refunded, in your account’s currency" title="Amount refunded, in your account’s currency"
                                    value="<?php echo p202_setup_e($refund['amount'] === '0' ? '' : $refund['amount']); ?>">
                                <?php if (count($refund['clicks']) > 1) { ?>
                                    <select class="form-select form-select-sm w-auto" name="click_id" required aria-label="The click this refund takes back from">
                                        <option value="">Click…</option>
                                        <?php foreach ($refund['clicks'] as $click) { ?>
                                            <option value="<?php echo (int) $click; ?>">click <?php echo (int) $click; ?></option>
                                        <?php } ?>
                                    </select>
                                <?php } ?>
                                <button type="submit" class="btn btn-primary btn-sm">Record refund</button>
                            </form>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>" class="d-inline"
                                data-p202-confirm="Close refund <?php echo p202_setup_e($refund['refund_id']); ?> with nothing recorded? Its amount stays counted in the sale.">
                                <?php echo p202_setup_token_field($token); ?>
                                <input type="hidden" name="action" value="close_refund">
                                <input type="hidden" name="refund_id" value="<?php echo p202_setup_e($refund['refund_id']); ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Close, no money returned</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="form-text mb-0">The amount is in your account’s currency and is taken from the order’s sale, at most what is left of it.</p>
    </div>
</section>
<?php } ?>

<section class="p202-panel">
    <div class="p202-panel__body">
        <form method="get" action="<?php echo p202_setup_e($self); ?>" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="outcome">Show</label>
                <select class="form-select<?php echo p202_setup_invalid($errors, 'outcome'); ?>" id="outcome" name="outcome">
                    <?php echo p202_setup_options($outcomeChoices, (string) ($params['outcome'] ?? '')); ?>
                </select>
                <?php echo p202_setup_feedback($errors, 'outcome'); ?>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="endpoint">Endpoint</label>
                <select class="form-select<?php echo p202_setup_invalid($errors, 'endpoint'); ?>" id="endpoint" name="endpoint">
                    <?php echo p202_setup_options($endpointChoices, (string) ($params['endpoint'] ?? '')); ?>
                </select>
                <?php echo p202_setup_feedback($errors, 'endpoint'); ?>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary">Show</button>
            </div>
        </form>

        <?php if ($rows === [] && $errors === []) { ?>
            <p class="text-body-secondary mb-0">Nothing here yet. A postback, pixel or payment notification shows up here as soon as it arrives.</p>
        <?php } elseif ($rows !== []) { ?>
        <div class="p202-table-wrap">
            <table class="table p202-table" id="inbound-log">
                <thead>
                    <tr><th>Time</th><th>Endpoint</th><th class="num">Status</th><th>Outcome</th><th>Why</th><th class="num">Click</th><th class="num">Conversion</th><th>Request</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $fields = $row['fields'];
                    $fieldsText = is_array($fields) ? (string) json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                    ?>
                    <tr class="<?php echo $row['accepted'] ? '' : 'table-warning'; ?>">
                        <td><?php echo p202_setup_e($when((int) $row['received_at'])); ?></td>
                        <td><?php echo p202_setup_e($endpointLabels[$row['endpoint']] ?? $row['endpoint']); ?><?php echo $row['user_id'] === null ? ' <span class="text-body-secondary">(account —)</span>' : ''; ?></td>
                        <td class="num"><?php echo (int) $row['http_status']; ?></td>
                        <td><?php echo p202_setup_e(str_replace('_', ' ', (string) $row['outcome'])); ?></td>
                        <td><?php echo p202_setup_e((string) $row['reason']); ?></td>
                        <td class="num"><?php echo $row['click_id'] === null ? '' : p202_setup_e((string) $row['click_id']); ?></td>
                        <td class="num"><?php echo $row['conversion_id'] === null ? '' : p202_setup_e((string) $row['conversion_id']); ?></td>
                        <td><?php if ($fieldsText !== '') { ?><details><summary>fields</summary><pre class="mb-0 small"><?php echo p202_setup_e($fieldsText); ?></pre></details><?php } ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($older !== null) { ?>
            <a class="btn btn-outline-secondary" href="<?php echo p202_setup_e($self . '?' . http_build_query(['before' => $older] + array_diff_key($params, ['before' => true]))); ?>">Older requests</a>
        <?php } ?>
        <?php } ?>
    </div>
</section>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
