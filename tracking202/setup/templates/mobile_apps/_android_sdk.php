<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps, an Android app: what the SDK needs — this server's
 * install intake and the app token — with a check that the intake answers,
 * and the newest installs with how each was classified (plan §5.3, §5.6).
 *
 * The SDK itself is PR 7's (sdk/android-attribution/): this panel prints
 * only what the wire contract fixes (21-app-sdk-contract.md), not an API
 * of an SDK this tree does not ship.
 *
 * @var array<string, mixed> $mobileApps
 * @var int $rowId
 * @var bool $canManage
 * @var string $self
 * @var string $origin
 * @var string $token
 * @var string $masked
 * @var callable $e
 */

$intake = $origin . '/api/v3/apps/installs';
$matchTone = static function (string $state): string {
    return match ($state) {
        'attributed' => 'p202-pill p202-pill--good',
        'bad_token', 'foreign_click', 'implausible', 'integrity_failed' => 'p202-pill p202-pill--bad',
        'pending_click', 'pending_integrity', 'integrity_unverified', 'outside_window', 'duplicate_click' => 'p202-pill p202-pill--warn',
        default => 'p202-pill',
    };
};
?>
<section class="p202-panel" id="sdk">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">App token &amp; SDK</h2>
        <p class="p202-panel__sub">The Android SDK reports the install once, on first launch, and the events after it. It needs this server's address and the app token, which ships inside the app and identifies it rather than protecting anything.</p>
    </div>
    <div class="p202-panel__body">
        <div class="p202-strip mb-3">
            <div class="p202-strip__row">
                <span class="p202-pill" data-receiver-pill data-url="<?php echo $e($intake); ?>" data-app-token="<?php echo $e($token); ?>">Checking…</span>
                <span class="p202-strip__label">Install intake</span>
                <span class="p202-strip__value"><code><?php echo $e($intake); ?></code></span>
                <span class="p202-strip__aside">
                    <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($origin); ?>">Copy server URL</button>
                </span>
            </div>
            <p class="p202-strip__note">
                The SDK is configured with the server URL <code><?php echo $e($origin); ?></code>; it adds the paths itself.
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-receiver-recheck>Re-check</button>
            </p>
        </div>

        <div class="p202-code">
            <span class="p202-code__value p202-code__value--masked" id="app-token"
                  data-p202-value="<?php echo $e($token); ?>"
                  data-p202-masked="<?php echo $e($masked); ?>"><?php echo $e($masked); ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-p202-reveal="#app-token">Reveal</button>
            <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($token); ?>">Copy</button>
            <?php if ($canManage) { ?>
                <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Replace this app token? The old token stops working immediately: builds that carry it can no longer report installs or events until they ship with the new one.">
                    <?php echo $mobileApps['csrf']; ?>
                    <input type="hidden" name="action" value="rotate_token">
                    <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Rotate…</button>
                </form>
            <?php } ?>
        </div>
        <p class="text-secondary small">A debug build can mark its installs as tests; they count only when Settings › Advanced says so.</p>
    </div>
</section>

<section class="p202-panel" id="recent">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Recent installs</h2>
        <p class="p202-panel__sub">The newest ten the SDK reported, with how each was classified.</p>
    </div>
    <div class="p202-panel__body">
        <?php if ($mobileApps['recentInstalls'] === null) { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">The installs for this app could not be read just now, so this list is not showing whether any have arrived. Reload the page; if it keeps happening the server log has the reason.</div>
            </div>
        <?php } elseif ($mobileApps['recentInstalls'] === []) { ?>
            <div class="p202-empty">
                <i class="bi bi-inbox p202-empty__icon"></i>
                <strong class="p202-empty__title">No installs yet</strong>
                <div>The SDK reports an install on the app's first launch. Point a campaign at the store link above and install the app through one of its clicks, or post one with <code>p202 app install simulate <?php echo $rowId; ?> --click N</code>.</div>
            </div>
        <?php } else { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead><tr><th>Received</th><th>Match</th><th>Why</th><th>Integrity</th><th>Click</th></tr></thead>
                    <tbody>
                    <?php foreach ($mobileApps['recentInstalls'] as $install) {
                        $state = (string)($install['match_state'] ?? ''); ?>
                        <tr>
                            <td><?php echo $e(gmdate('M j, H:i', (int)($install['received_at'] ?? 0))); ?></td>
                            <td><span class="<?php echo $e($matchTone($state)); ?>"><?php echo $e(str_replace('_', ' ', $state)); ?></span><?php if ((int)($install['is_test'] ?? 0) === 1) { ?> <span class="p202-pill p202-pill--warn">test</span><?php } ?></td>
                            <td class="small"><?php echo $e((string)($install['match_reason'] ?? '')); ?></td>
                            <td><?php echo $e(str_replace('_', ' ', (string)($install['integrity_state'] ?? ''))); ?></td>
                            <td class="num"><?php echo ($install['click_id'] ?? null) === null ? '<span class="text-body-secondary">none</span>' : (int)$install['click_id']; ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</section>
