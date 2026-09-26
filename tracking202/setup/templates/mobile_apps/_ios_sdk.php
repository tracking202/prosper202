<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps, an iOS app: the app token and the Swift SDK, the
 * Info.plist keys, and the newest postbacks Apple sent.
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
?>
<section class="p202-panel" id="sdk">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">App token &amp; SDK</h2>
        <p class="p202-panel__sub">Devices fetch this app's goals and conversion values with the token. It ships inside the app, so it identifies the app rather than protecting anything.</p>
    </div>
    <div class="p202-panel__body">
        <div class="p202-code">
            <span class="p202-code__value p202-code__value--masked" id="app-token"
                  data-p202-value="<?php echo $e($token); ?>"
                  data-p202-masked="<?php echo $e($masked); ?>"><?php echo $e($masked); ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-p202-reveal="#app-token">Reveal</button>
            <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($token); ?>">Copy</button>
            <?php if ($canManage) { ?>
                <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Replace this app token? The old token stops working immediately. Apps keep their last cached schema until they fetch with the new one.">
                    <?php echo $mobileApps['csrf']; ?>
                    <input type="hidden" name="action" value="rotate_token">
                    <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Rotate…</button>
                </form>
            <?php } ?>
        </div>

        <h3 class="p202-panel__title mt-4">Info.plist keys</h3>
        <?php
        $plist = "<key>NSAdvertisingAttributionReportEndpoint</key>\n<string>{$origin}</string>\n"
            . "<key>AttributionCopyEndpoint</key>\n<string>{$origin}</string>\n"
            . "<key>EligibleForAdAttributionKitReengagementPostbackCopies</key>\n<true/>";
        ?>
        <div class="p202-code">
            <pre class="p202-code__value mb-0"><?php echo $e($plist); ?></pre>
            <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($plist); ?>">Copy</button>
        </div>

        <h3 class="p202-panel__title mt-4">Swift package: P202Attribution</h3>
        <?php
        // The SDK's real API (sdk/ios-attribution/README.md): configure
        // once at launch, then log events for the goals to evaluate on
        // the device.
        $swift = "P202Attribution.shared.configure(\n    endpoint: URL(string: \"{$origin}\")!,\n    appToken: \"" . ($token === '' ? '' : mb_substr($token, 0, 4) . '…' . mb_substr($token, -4)) . "\"\n)\ntry P202Attribution.shared.logEvent(\"purchase\")";
        ?>
        <div class="p202-code">
            <pre class="p202-code__value mb-0"><?php echo $e($swift); ?></pre>
            <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e(str_replace(mb_substr($token, 0, 4) . '…' . mb_substr($token, -4), $token, $swift)); ?>">Copy</button>
        </div>
        <p class="text-secondary small">Copy puts the whole token in; the snippet shows it shortened
            so a screenshot of this page does not leak it.</p>
    </div>
</section>

<section class="p202-panel" id="recent">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Recent postbacks</h2>
        <p class="p202-panel__sub">The newest ten Apple sent for this app, whatever their signature.</p>
    </div>
    <div class="p202-panel__body">
        <?php if ($mobileApps['recent'] === null) { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">The postbacks for this app could not be read just now, so this list is not showing whether any have arrived. Reload the page; if it keeps happening the server log has the reason.</div>
            </div>
        <?php } elseif ($mobileApps['recent'] === []) { ?>
            <div class="p202-empty">
                <i class="bi bi-inbox p202-empty__icon"></i>
                <strong class="p202-empty__title">Nothing received yet</strong>
                <div>Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Ship a build with the Info.plist keys above, then check back.</div>
            </div>
        <?php } else { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead><tr><th>Received</th><th>Protocol</th><th>Ad network</th><th>Type</th><th>Value</th><th>Signature</th></tr></thead>
                    <tbody>
                    <?php foreach ($mobileApps['recent'] as $postback) {
                        $state = (string)($postback['signature_state'] ?? '');
                        $tone = ['valid' => 'p202-pill--good', 'invalid' => 'p202-pill--bad', 'development' => 'p202-pill--warn'][$state] ?? ''; ?>
                        <tr>
                            <td><?php echo $e(date('M j, H:i', (int)$postback['received_at'])); ?></td>
                            <td><?php echo $e((string)($postback['protocol'] ?? '')); ?> <?php echo $e((string)($postback['version'] ?? '')); ?></td>
                            <td><?php echo $e((string)($postback['ad_network_id'] ?? '')); ?></td>
                            <td><?php echo $e((string)($postback['conversion_type'] ?? '')); ?></td>
                            <td><?php echo $e($postback['conversion_value'] ?? ($postback['coarse_conversion_value'] ?? '')); ?></td>
                            <td><span class="p202-pill <?php echo $tone; ?>"><?php echo $e($state); ?></span></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</section>
