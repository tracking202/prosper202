<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps: the Postbacks sent tab — the traffic-source
 * postbacks the app installs' goals queued (the notification outbox), and
 * whether each went out: sent, failed (every attempt refused; the reason is
 * the last error), pending (waiting, or backing off), cancelled (replaced
 * before it went out) or suppressed (a correction the traffic source has no
 * correction URL for).
 *
 * @var array<string, mixed> $mobileReport
 * @var array<string, mixed> $filters
 * @var callable $e
 * @var callable $num
 * @var callable $link
 * @var callable $empty
 * @var array<string, array{name: string, key: string, platform: string}> $appNames
 */

$rows = $mobileReport['notifications'];
$summary = $mobileReport['summary'];
$pagination = $mobileReport['pagination'];
$statusTone = static fn (string $status): string => [
    'sent' => 'p202-pill p202-pill--good',
    'failed' => 'p202-pill p202-pill--bad',
    'pending' => 'p202-pill p202-pill--warn',
][$status] ?? 'p202-pill';
$statusSub = [
    'pending' => 'waiting or backing off',
    'sent' => 'the traffic source answered',
    'failed' => 'every attempt refused',
    'cancelled' => 'replaced before it went out',
    'suppressed' => 'no correction URL',
];
?>
<?php if ($rows === null) { ?>
    <?php echo $empty('bi-exclamation-triangle', 'The postbacks could not be read', 'The message above says why. This is not a statement that none were sent.'); ?>
<?php } else { ?>
    <div class="p202-tiles">
        <?php foreach ($statusSub as $status => $sub) { ?>
            <div class="p202-tile<?php echo $status === 'failed' && (int)$summary['failed'] > 0 ? ' is-bad' : ($status === 'sent' ? ' is-good' : ''); ?>" data-outbox-status="<?php echo $e($status); ?>">
                <div class="p202-tile__label"><?php echo $e(ucfirst($status)); ?></div>
                <div class="p202-tile__value"><?php echo $num($summary[$status] ?? 0); ?></div>
                <div class="p202-tile__sub"><?php echo $e($sub); ?></div>
            </div>
        <?php } ?>
    </div>

    <?php if ($rows === []) { ?>
        <?php echo $empty('bi-send', 'No postbacks queued in this range',
            'A traffic source is told when an attributed install reaches a goal its campaign pays for, through a server-to-server pixel on the traffic source\'s account (Setup › Traffic Sources). Widen the range, or check that the account has one.'); ?>
    <?php } else { ?>
        <section class="p202-section">
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead>
                        <tr><th>Queued</th><th>App</th><th>Goal</th><th>Kind</th><th>Status</th><th class="num">Attempts</th><th>Last error</th><th>URL</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $status = (string)$row['status']; ?>
                        <tr>
                            <td><?php echo $e(gmdate('Y-m-d H:i', (int)$row['created_at'])); ?></td>
                            <td><?php echo $e($row['app_name'] ?? ($appNames[(string)($row['registration_id'] ?? '')]['name'] ?? '')); ?></td>
                            <td><?php echo $e($row['goal_name'] ?? ''); ?></td>
                            <td><?php echo $e((string)$row['kind']); ?></td>
                            <td><span class="<?php echo $e($statusTone($status)); ?>"><?php echo $e($status); ?></span><?php if ($status === 'sent' && $row['sent_at'] !== null) { ?> <span class="text-secondary small"><?php echo $e(gmdate('H:i', (int)$row['sent_at'])); ?></span><?php } ?></td>
                            <td class="num"><?php echo (int)$row['attempts']; ?></td>
                            <td class="small"><?php echo $e((string)($row['last_error'] ?? '')); ?></td>
                            <td class="font-monospace small text-break"><?php echo $e((string)$row['url']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small">
                <?php echo $num($pagination['rows']); ?> <?php echo $pagination['rows'] === 1 ? 'postback' : 'postbacks'; ?><?php echo $filters['status'] !== '' ? ' ' . $e($filters['status']) : ''; ?> in this range.
                A failed postback is retried with backoff before it is marked failed; the worker in 202-cronjobs sends them.
            </p>
            <?php if ($pagination['pages'] > 1) {
                $page = $pagination['page'];
                $pages = $pagination['pages']; ?>
                <nav class="mt-3" aria-label="Pages">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => max(1, $page - 1)])); ?>"<?php echo $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&lsaquo;</a>
                        </li>
                        <li class="page-item active" aria-current="page"><span class="page-link"><?php echo (int)$page; ?> of <?php echo (int)$pages; ?></span></li>
                        <li class="page-item<?php echo $page >= $pages ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => min($pages, $page + 1)])); ?>"<?php echo $page >= $pages ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&rsaquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php } ?>
        </section>
    <?php } ?>
<?php } ?>
