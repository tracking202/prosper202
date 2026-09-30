<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps, an Android app: Play Integrity (plan §5.6, §5.11).
 *
 * Opt-in: `off` by default, because it needs the app owner's Google Cloud
 * project. The panel says where the app stands — its mode, its service
 * account (never the key), its installs by verdict, today's decodes against
 * Google's default quota — and the forms that change it sit under a closed
 * Advanced disclosure, in the order PR 6's rules require: the service
 * account first, then the mode with the Cloud project number. Every refusal
 * is the API's own sentence.
 *
 * @var array<string, mixed> $mobileApps
 * @var array<string, mixed> $form
 * @var int $rowId
 * @var bool $canManage
 * @var string $self
 * @var callable $e
 * @var callable $fieldError
 * @var callable $invalid
 */

$status = $mobileApps['integrity'];
$integrityFailed = in_array($mobileApps['failedForm'] ?? '', ['integrity_mode', 'integrity_credential', 'integrity_clear'], true);
$modeDescriptions = [
    'off' => 'Off: no token is requested, and attribution never waits for one.',
    'observe' => 'Observe: each install\'s verdict is recorded and reported; attribution is unchanged.',
    'require' => 'Require: an install is attributed and paid only once its verdict passes.',
];
?>
<section class="p202-panel" id="integrity">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Play Integrity</h2>
        <?php if ($status !== null) {
            $mode = (string)$status['integrity_mode']; ?>
            <span class="p202-pill <?php echo $mode === 'off' ? '' : ($mode === 'require' ? 'p202-pill--good' : 'p202-pill--accent'); ?>"><?php echo $e($mode); ?></span>
        <?php } ?>
        <p class="p202-panel__sub">Google's check that an install came from the real app on a real device. Off by default: it needs your Google Cloud project.</p>
    </div>
    <div class="p202-panel__body">
        <?php if ($status === null) { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">Play Integrity could not be read for this app just now. Reload the page; if it keeps happening the server log has the reason.</div>
            </div>
        <?php } else {
            $credential = $status['credential'];
            $byState = array_filter((array)$status['installs']['by_integrity_state'], static fn (int $n): bool => $n > 0);
            $held = array_filter((array)$status['installs']['by_match_state'], static fn (int $n): bool => $n > 0); ?>
            <p class="mb-3"><?php echo $e($modeDescriptions[(string)$status['integrity_mode']] ?? ''); ?></p>
            <div class="p202-strip mb-3">
                <div class="p202-strip__row">
                    <span class="p202-pill <?php echo $credential === null ? '' : 'p202-pill--good'; ?>"><?php echo $credential === null ? 'none' : 'set'; ?></span>
                    <span class="p202-strip__label">Service account</span>
                    <span class="p202-strip__value"><?php echo $credential === null ? 'not set' : $e((string)($credential['client_email'] ?? '') . ' · key ' . (string)($credential['private_key_id'] ?? '')); ?></span>
                </div>
                <div class="p202-strip__row">
                    <span class="p202-pill <?php echo $status['integrity_cloud_project_number'] === null ? '' : 'p202-pill--good'; ?>"><?php echo $status['integrity_cloud_project_number'] === null ? 'none' : 'set'; ?></span>
                    <span class="p202-strip__label">Cloud project number</span>
                    <span class="p202-strip__value"><?php echo $e($status['integrity_cloud_project_number'] ?? 'not set'); ?></span>
                </div>
                <div class="p202-strip__row">
                    <span class="p202-pill"><?php echo number_format((int)$status['usage']['decoded_since_utc_midnight']); ?></span>
                    <span class="p202-strip__label">Decoded today</span>
                    <span class="p202-strip__value">of Google's default <?php echo number_format((int)$status['usage']['default_daily_quota']); ?> a day (UTC); a token refused for quota waits and is retried, never waved through</span>
                </div>
            </div>
            <?php if ($byState !== [] || $held !== []) { ?>
                <div class="p202-table-wrap mb-3">
                    <table class="table p202-table">
                        <thead><tr><th>Installs</th><th class="num">Count</th></tr></thead>
                        <tbody>
                        <?php foreach ($byState as $state => $n) { ?>
                            <tr><td>verdict <?php echo $e(str_replace('_', ' ', (string)$state)); ?></td><td class="num"><?php echo number_format($n); ?></td></tr>
                        <?php } ?>
                        <?php foreach ($held as $state => $n) { ?>
                            <tr><td><?php echo $e(str_replace('_', ' ', (string)$state)); ?></td><td class="num"><?php echo number_format($n); ?></td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>

            <?php if ($canManage) { ?>
                <details class="p202-disclosure" data-p202-remember="setup-mobile-apps-integrity"<?php echo $integrityFailed ? ' open' : ''; ?>>
                    <summary>Advanced <span class="p202-disclosure__hint">service account, mode, Cloud project number</span></summary>
                    <div class="p202-disclosure__body">
                        <h3 class="h6">1. Service account</h3>
                        <p class="form-text mt-0">A Google Cloud service account with the Play Integrity API, as its JSON key file. The key is stored encrypted and never shown again.</p>
                        <form method="post" action="<?php echo $e($self . '#integrity'); ?>" enctype="multipart/form-data" class="mb-3">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="integrity_credential">
                            <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                            <div class="mb-2">
                                <label class="form-label" for="credential_file">Key file</label>
                                <input class="form-control<?php echo $invalid('credential'); ?>" type="file" id="credential_file" name="credential_file" accept="application/json,.json">
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="credential">or paste its JSON</label>
                                <?php /* Never refilled: a refused key is not echoed back into the page. */ ?>
                                <textarea class="form-control font-monospace<?php echo $invalid('credential'); ?>" id="credential" name="credential" rows="3" autocomplete="off" spellcheck="false" placeholder='{"type": "service_account", "client_email": "…", "private_key": "…"}'></textarea>
                                <?php echo $fieldError('credential'); ?>
                            </div>
                            <div class="p202-form-actions justify-content-start">
                                <button class="btn btn-secondary" type="submit"><?php echo $credential === null ? 'Save service account' : 'Replace service account'; ?></button>
                            </div>
                        </form>
                        <?php if ($credential !== null) { ?>
                            <form method="post" action="<?php echo $e($self . '#integrity'); ?>" class="mb-3" data-p202-confirm="Delete this app's service account? Refused while the mode is observe or require, or while installs still wait for a verdict.">
                                <?php echo $mobileApps['csrf']; ?>
                                <input type="hidden" name="action" value="integrity_clear">
                                <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                                <button class="btn btn-link btn-sm text-danger p-0" type="submit">Delete the service account</button>
                            </form>
                        <?php } ?>

                        <h3 class="h6">2. Mode</h3>
                        <form method="post" action="<?php echo $e($self . '#integrity'); ?>">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="integrity_mode">
                            <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                            <?php $shownMode = ($mobileApps['failedForm'] ?? '') === 'integrity_mode' ? (string)($form['integrity_mode'] ?? '') : (string)$status['integrity_mode']; ?>
                            <fieldset class="mb-3">
                                <legend class="form-label">Mode</legend>
                                <?php foreach (\Tracking202\Setup\MobileAppsController::integrityModes() as $modeValue) { ?>
                                    <div class="form-check">
                                        <input class="form-check-input<?php echo $invalid('integrity_mode'); ?>" type="radio" name="integrity_mode" id="integrity_mode_<?php echo $e($modeValue); ?>" value="<?php echo $e($modeValue); ?>"<?php echo $shownMode === $modeValue ? ' checked' : ''; ?>>
                                        <label class="form-check-label" for="integrity_mode_<?php echo $e($modeValue); ?>"><?php echo $e($modeDescriptions[$modeValue] ?? $modeValue); ?></label>
                                    </div>
                                <?php } ?>
                                <?php echo $fieldError('integrity_mode'); ?>
                            </fieldset>
                            <div class="mb-3">
                                <label class="form-label" for="integrity_cloud_project_number">Cloud project number</label>
                                <input class="form-control<?php echo $invalid('integrity_cloud_project_number'); ?>" type="text" inputmode="numeric" id="integrity_cloud_project_number" name="integrity_cloud_project_number"
                                       value="<?php echo $e(($mobileApps['failedForm'] ?? '') === 'integrity_mode' ? ($form['integrity_cloud_project_number'] ?? '') : ''); ?>"
                                       placeholder="<?php echo $e($status['integrity_cloud_project_number'] ?? 'the number on the Cloud project dashboard'); ?>">
                                <div class="form-text">The project NUMBER (digits), not its id. Observe and require need it; once set it can be replaced, not cleared, so leave this empty to keep <?php echo $status['integrity_cloud_project_number'] === null ? 'it unset' : 'the one set'; ?>.</div>
                                <?php echo $fieldError('integrity_cloud_project_number'); ?>
                            </div>
                            <div class="p202-form-actions justify-content-start">
                                <button class="btn btn-secondary" type="submit">Save mode</button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php } ?>
        <?php } ?>
    </div>
</section>
