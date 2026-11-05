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
use Prosper202\InboundLog\InboundLog;

/*
 * Setup › Inbound Log: the postbacks, pixels and payment notifications this
 * install answered for the account, newest first, and why each one that
 * recorded nothing was refused. The same list as GET
 * /api/v3/conversions/inbound-log; secrets and visitor details are never kept.
 */

$userId = (int) $_SESSION['user_id'];
$base = get_absolute_url();
$self = $base . 'tracking202/setup/inbound_log.php';
$seesUnattributed = $userObj->hasPermission('add_users');
$params = array_intersect_key($_GET, array_flip(['outcome', 'endpoint', 'before']));
$params = array_filter($params, static fn (mixed $v): bool => is_string($v) && $v !== '');
$rows = [];
$errors = [];
$limit = 100;
try {
    $filters = InboundLogController::filters($params);
    $rows = (new InboundLog($db))->list($userId, $seesUnattributed, $filters, $limit);
} catch (ValidationException $e) {
    $errors = $e->getFieldErrors();
} catch (\Throwable $e) {
    error_log(p202UncaughtLogLine('Setup › Inbound Log: the log could not be read', $e));
    $errors['log'] = 'The log could not be read just now. If it keeps failing, the server\'s error log says why (an upgrade may be pending).';
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
if ($seesUnattributed) {
    echo p202_flash('info', 'As an admin you also see the requests no account could be found for (an unknown campaign, a signature no stored key verifies); they show "—" as the account.');
}
?>

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
                        <td><?php echo p202_setup_e($endpointLabels[$row['endpoint']] ?? $row['endpoint']); ?><?php echo $seesUnattributed && $row['user_id'] === null ? ' <span class="text-body-secondary">(account —)</span>' : ''; ?></td>
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
