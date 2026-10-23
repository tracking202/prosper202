<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps: an app's settings form, on the list's edit view and
 * at the foot of the app's page.
 *
 * The name and notes are the common case. Test signals — and for Android
 * the attribution window, whether client revenue may be paid and the
 * fraud limits (plan §7.1) — are under a closed Advanced disclosure, each
 * default said in one line.
 *
 * @var array<string, mixed> $settingsApp the registration
 * @var string $settingsReturn 'app' (back to its page) or 'list'
 * @var array<string, mixed> $mobileApps
 * @var array<string, mixed> $form
 * @var callable $e
 * @var callable $fieldError
 * @var callable $invalid
 * @var callable $platformLabel
 * @var callable $keyLabel
 * @var string $self
 */

$sApp = $settingsApp;
$sAndroid = (string)($sApp['platform'] ?? '') === 'android';
// A refused save shows what was typed; otherwise what is stored.
$sPosted = ($mobileApps['failedForm'] ?? '') === 'update';
$sValue = static fn (string $field, mixed $stored): string => (string)($sPosted ? ($form[$field] ?? '') : $stored);
$sChecked = static fn (string $field, mixed $stored): bool => $sPosted ? isset($form[$field]) : (int)$stored === 1;
$sLimitFields = ['ctit_min_seconds', 'ctit_max_seconds', 'install_cap_per_minute', 'event_cap_per_minute', 'fast_goal_seconds', 'fast_goal_policy'];
$sAdvancedOpen = $sPosted && array_intersect(['attribution_window_days', 'trust_client_revenue', ...$sLimitFields], array_keys((array)($mobileApps['fieldErrors'] ?? []))) !== [];
// One numeric abuse limit (AppLimits, FastGoalPolicy): the stored value, its
// default said in one line.
$sLimit = static function (string $field, string $label, string $default, string $help) use ($e, $sValue, $sApp, $invalid, $fieldError): string {
    return '<div class="mb-3">'
        . '<label class="form-label" for="settings_' . $field . '">' . $e($label) . '</label>'
        . '<input class="form-control' . $invalid($field) . '" type="text" inputmode="numeric" id="settings_' . $field . '" name="' . $field . '"'
        . ' value="' . $e($sValue($field, $sApp[$field] ?? $default)) . '" maxlength="8">'
        . '<div class="form-text">' . $e($help) . '</div>'
        . $fieldError($field)
        . '</div>';
};
?>
<form method="post" action="<?php echo $e($self); ?>">
    <?php echo $mobileApps['csrf']; ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="return_to" value="<?php echo $e($settingsReturn); ?>">
    <input type="hidden" name="registration_id" value="<?php echo (int)$sApp['registration_id']; ?>">
    <input type="hidden" name="platform" value="<?php echo $e($sApp['platform'] ?? ''); ?>">
    <div class="p202-decided mb-3"><i class="bi bi-check2-circle"></i>
        <?php echo $e($sApp['app_name']); ?> · <?php echo $e($platformLabel($sApp['platform'] ?? 'ios')); ?> · <?php echo $e($keyLabel($sApp['platform'] ?? 'ios')); ?> <?php echo $e($sApp['app_key']); ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="settings_app_name">App name</label>
        <input class="form-control<?php echo $invalid('app_name'); ?>" type="text" id="settings_app_name" name="app_name" value="<?php echo $e($sValue('app_name', $sApp['app_name'])); ?>" maxlength="255" required>
        <?php echo $fieldError('app_name'); ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="settings_notes">Notes <span class="text-body-secondary">optional</span></label>
        <input class="form-control<?php echo $invalid('notes'); ?>" type="text" id="settings_notes" name="notes" value="<?php echo $e($sValue('notes', $sApp['notes'] ?? '')); ?>" maxlength="500">
        <?php echo $fieldError('notes'); ?>
    </div>
    <details class="p202-disclosure mb-3" data-p202-remember="setup-mobile-apps-settings"<?php echo $sAdvancedOpen ? ' open' : ''; ?>>
        <summary>Advanced <span class="p202-disclosure__hint"><?php echo $sAndroid ? 'test installs, attribution window, client revenue, fraud limits' : 'development postbacks'; ?></span></summary>
        <div class="p202-disclosure__body">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="settings_accept_test_signals" name="accept_test_signals" value="1"<?php echo $sChecked('accept_test_signals', $sApp['accept_test_signals'] ?? 0) ? ' checked' : ''; ?>>
                <label class="form-check-label" for="settings_accept_test_signals">
                    <?php echo $sAndroid ? 'Count test installs' : 'Accept development postbacks'; ?>
                    <span class="d-block form-text"><?php echo $sAndroid
                        ? 'Off by default: an install a debug build marks as a test is stored and shown, and pays nothing.'
                        : 'Off by default: postbacks signed with Apple\'s development key prove only that some device was in Developer Mode. Turn on while testing.'; ?></span>
                </label>
            </div>
            <?php if ($sAndroid) { ?>
                <div class="mb-3">
                    <label class="form-label" for="settings_attribution_window_days">Attribution window, days</label>
                    <input class="form-control<?php echo $invalid('attribution_window_days'); ?>" type="text" inputmode="numeric" id="settings_attribution_window_days" name="attribution_window_days"
                           value="<?php echo $e($sValue('attribution_window_days', $sApp['attribution_window_days'] ?? '7')); ?>" maxlength="3">
                    <div class="form-text">7 by default: an install that begins more than this many days after its click is not attributed to it.</div>
                    <?php echo $fieldError('attribution_window_days'); ?>
                </div>
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" id="settings_trust_client_revenue" name="trust_client_revenue" value="1"<?php echo $sChecked('trust_client_revenue', $sApp['trust_client_revenue'] ?? 0) ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="settings_trust_client_revenue">
                        Pay goals from the revenue the app reports
                        <span class="d-block form-text">Off by default: the app token ships inside the app, so anyone can report revenue with it. Reported revenue is stored and shown, and a goal valued from it pays only when this is on.</span>
                    </label>
                    <?php echo $fieldError('trust_client_revenue'); ?>
                </div>
                <?php
                echo $sLimit('ctit_min_seconds', 'Flag installs faster than, seconds', '10',
                    '10 by default: an install that began sooner than this after its click is flagged short, the mark of click injection. Flags are shown in Analyze › Mobile Apps; they do not refuse the install.');
                echo $sLimit('ctit_max_seconds', 'Flag installs slower than, seconds', '86400',
                    '86400 (one day) by default: an install that began later than this after its click is flagged long, the mark of click spamming.');
                echo $sLimit('install_cap_per_minute', 'Installs a minute, at most', '300',
                    '300 by default: past it the app\'s SDK is told to retry later, and nothing is recorded until it does.');
                echo $sLimit('event_cap_per_minute', 'Events a minute per install, at most', '200',
                    '200 by default, and never below 100 (one full batch): past it that install\'s events wait on the device and are retried.');
                echo $sLimit('fast_goal_seconds', 'Flag goals reached within, seconds of the install', '5',
                    '5 by default: a goal reached sooner than this after the install is flagged, since a person needs longer. 0 flags none.');
                ?>
                <div class="mb-1">
                    <label class="form-label" for="settings_fast_goal_policy">Goals flagged as too fast</label>
                    <select class="form-select<?php echo $invalid('fast_goal_policy'); ?>" id="settings_fast_goal_policy" name="fast_goal_policy">
                        <?php $sPolicy = $sValue('fast_goal_policy', $sApp['fast_goal_policy'] ?? 'count'); ?>
                        <option value="count"<?php echo $sPolicy === 'count' ? ' selected' : ''; ?>>Pay them, and show the flag</option>
                        <option value="hold"<?php echo $sPolicy === 'hold' ? ' selected' : ''; ?>>Hold them: record, flag, do not pay</option>
                    </select>
                    <div class="form-text">Pay by default: the flag is a heuristic, and an app whose first goal is opening it reaches that in seconds. A held goal is never paid or sent to the traffic source.</div>
                    <?php echo $fieldError('fast_goal_policy'); ?>
                </div>
            <?php } ?>
        </div>
    </details>
    <div class="p202-form-actions">
        <?php if ($settingsReturn === 'list') { ?>
            <a class="btn btn-link" href="<?php echo $e($self); ?>">Cancel</a>
        <?php } ?>
        <?php /* One primary action per page (UI standard, rule 5): on the
                 app's page that is the panel at the top, so settings save
                 with a secondary button there. */ ?>
        <button class="btn <?php echo $settingsReturn === 'list' ? 'btn-primary' : 'btn-secondary'; ?>" type="submit">Save changes</button>
    </div>
</form>
