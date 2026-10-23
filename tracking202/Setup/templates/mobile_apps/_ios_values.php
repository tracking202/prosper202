<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps, an iOS app: its SKAN conversion values — which fine
 * or coarse value means which goal was reached (plan §4.5, §5.9).
 *
 * A value names any goal a device can reach (the SDK evaluates it on the
 * device, so nothing counted from a click): one of the app's own goals, an
 * account-wide one, or — what an operator usually knows — a new event,
 * which becomes the app's plain goal for it. Editing a value's meaning is
 * warned about where it is made: devices keep the schema they fetched, so
 * for SkanEncodingTimeline::HORIZON_DAYS a postback carrying it may mean
 * either, and is reported as ambiguous_encoding where the two disagree.
 *
 * @var array<string, mixed> $mobileApps
 * @var array<string, mixed> $form
 * @var int $rowId
 * @var bool $canManage
 * @var string $self
 * @var callable $e
 * @var callable $money
 * @var callable $fieldError
 * @var callable $invalid
 * @var string $currencySymbol
 * @var bool $symbolLeads
 */

use Api\V3\Apps\Apple\SkanEncodingTimeline;

/*
 * Which rule the form is editing. The link carries ?rule_edit=N, but the
 * form posts to $self with NO query string — so after a refused save the
 * re-render saw no rule_edit, dropped the hidden rule_id, and the
 * corrected resubmission created a new rule instead of updating the one
 * being edited: usually a duplicate conflict, and no way to finish the
 * edit without starting over. The submitted body is the other record of
 * which rule it was, and it is the one that survives the POST.
 */
$editingRuleId = (int)($_GET['rule_edit'] ?? $form['rule_id'] ?? 0);
$editRule = null;
foreach ($mobileApps['rules'] as $candidate) {
    if ($editingRuleId > 0 && (int)($candidate['encoding_id'] ?? 0) === $editingRuleId) {
        $editRule = $candidate;
    }
}

/*
 * The goals a value may name, for the menu: this app's own and the
 * account's, current and device-reachable (a window from the click is one
 * no device can evaluate — the API refuses it, so the menu does not offer
 * it). The install goal is Android's and never an iOS app's.
 */
$goalOptions = [];
$reachable = static fn (array $g): bool => $g['archived_at'] === null
    && (string)(($g['definition']['within']['from'] ?? '')) !== 'click'
    && ($g['builtin'] ?? null) === null;
foreach ([['own', 'This app\'s goals'], ['account', 'Account-wide goals']] as [$key, $label]) {
    $options = [];
    foreach ((array)($mobileApps['goals'][$key] ?? []) as $g) {
        if ($reachable($g)) {
            $options[(string)$g['goal_id']] = (string)$g['name'];
        }
    }
    if ($options !== []) {
        $goalOptions[$key] = ['label' => $label, 'options' => $options];
    }
}
?>
<section class="p202-panel" id="conversion-values">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Conversion values</h2>
        <p class="p202-panel__sub">Which SKAdNetwork value means which goal was reached, and what a postback carrying it is worth.</p>
        <span class="p202-pill p202-pill--accent"><?php echo count($mobileApps['rules']); ?> <?php echo count($mobileApps['rules']) === 1 ? 'rule' : 'rules'; ?></span>
    </div>
    <div class="p202-panel__body">
        <?php if ($mobileApps['rules'] === []) { ?>
            <div class="p202-empty">
                <i class="bi bi-sliders p202-empty__icon"></i>
                <strong class="p202-empty__title">No rules yet, so nothing decodes</strong>
                <div>Most apps start with install, trial and purchase on fine values 1, 10 and 40, and the three coarse buckets. Add those now and edit them to match what your app reports.</div>
                <?php if ($canManage) { ?>
                    <div class="p202-empty__action">
                        <form method="post" action="<?php echo $e($self); ?>">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="starter_schema">
                            <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                            <button class="btn btn-primary btn-sm" type="submit">Use starter schema</button>
                        </form>
                    </div>
                <?php } ?>
            </div>
        <?php } else { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead><tr><th>Kind</th><th>Value</th><th>Goal</th><th class="num">Revenue</th><?php if ($canManage) { ?><th></th><?php } ?></tr></thead>
                    <tbody>
                    <?php foreach ($mobileApps['rules'] as $rule) { ?>
                        <tr>
                            <td><?php echo $rule['fine_value'] === null ? 'Coarse' : 'Fine'; ?></td>
                            <td><?php echo $e($rule['fine_value'] === null ? (string)$rule['coarse_value'] : (string)$rule['fine_value']); ?></td>
                            <td><?php echo $e($rule['event_name']); ?><?php if ((string)$rule['goal_name'] !== (string)$rule['event_name']) { ?> <span class="text-body-secondary"><?php echo $e($rule['goal_name']); ?></span><?php } ?></td>
                            <td class="num"><?php echo $e($money($rule['revenue'])); ?></td>
                            <?php if ($canManage) { ?>
                                <td class="num">
                                    <a class="p202-list__action" href="<?php echo $e($self . '?app=' . $rowId . '&rule_edit=' . (int)$rule['encoding_id'] . '#conversion-values'); ?>">edit</a>
                                    <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Remove this rule? Postbacks that carry the value keep decoding under its old meaning for <?php echo SkanEncodingTimeline::HORIZON_DAYS; ?> days, because devices set it before the change; after that they no longer decode.">
                                        <?php echo $mobileApps['csrf']; ?>
                                        <input type="hidden" name="action" value="rule_remove">
                                        <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                                        <input type="hidden" name="rule_id" value="<?php echo (int)$rule['encoding_id']; ?>">
                                        <button class="p202-list__action p202-list__action--danger" type="submit">remove</button>
                                    </form>
                                </td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($canManage) {
            $posted = ($mobileApps['failedForm'] ?? '') === 'rule_save';
            $kind = $editRule !== null && !$posted ? ($editRule['fine_value'] === null ? 'coarse' : 'fine') : (string)($form['kind'] ?? 'fine');
            /*
             * What each value field should show: what was just submitted,
             * else the rule being edited. A new rule refused for an
             * unrelated field (a non-numeric revenue, say) must come back
             * with the value that was chosen, or correcting the revenue
             * would create a rule for 0 instead.
             */
            $shownFine = $posted ? ($form['fine_value'] ?? null) : ($editRule['fine_value'] ?? null);
            $shownCoarse = $posted ? (string)($form['coarse_value'] ?? '') : (string)($editRule['coarse_value'] ?? '');
            $goalChoice = $posted
                ? (string)($form['goal_choice'] ?? 'event')
                : ($editRule !== null && isset($goalOptions['own']['options'][(string)$editRule['goal_id']]) || $editRule !== null && isset($goalOptions['account']['options'][(string)$editRule['goal_id']])
                    ? (string)$editRule['goal_id']
                    : 'event');
            $shownEvent = $posted ? (string)($form['event_name'] ?? '') : (string)($editRule['event_name'] ?? '');
            $shownRevenue = $posted ? (string)($form['revenue'] ?? '') : ($editRule !== null ? number_format((float)$editRule['revenue'], 2, '.', '') : '0.00'); ?>
            <form method="post" action="<?php echo $e($self); ?>" class="p202-section" id="rule-form">
                <?php echo $mobileApps['csrf']; ?>
                <input type="hidden" name="action" value="rule_save">
                <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                <?php if ($editRule !== null) { ?><input type="hidden" name="rule_id" value="<?php echo (int)$editRule['encoding_id']; ?>"><?php } ?>
                <?php if ($editRule !== null) {
                    echo p202_flash('warn', 'Changing what this value means: devices keep using the schema they already fetched for up to '
                        . SkanEncodingTimeline::SCHEMA_MAX_AGE_DAYS . ' days, and a postback can arrive up to '
                        . (SkanEncodingTimeline::CONVERSION_WINDOW_DAYS + SkanEncodingTimeline::DELIVERY_DELAY_DAYS) . ' days after the value was set, so until '
                        . gmdate('j M Y', time() + SkanEncodingTimeline::HORIZON_SECONDS)
                        . ' a postback carrying it is reported as ambiguous_encoding, and credited to neither meaning, wherever the old and new meanings disagree.');
                } ?>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <span class="form-label d-block">Kind</span>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="kind" id="kind_fine" value="fine" <?php echo $kind === 'fine' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="kind_fine">Fine value</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="kind" id="kind_coarse" value="coarse" <?php echo $kind === 'coarse' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="kind_coarse">Coarse</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-2" data-kind-fine>
                        <label class="form-label" for="fine_value">Fine value</label>
                        <select class="form-select<?php echo $invalid('fine_value'); ?>" id="fine_value" name="fine_value">
                            <?php for ($i = 0; $i <= 63; $i++) {
                                $selected = $shownFine !== null && $shownFine !== '' && (int)$shownFine === $i; ?>
                                <option value="<?php echo $i; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php } ?>
                        </select>
                        <?php echo $fieldError('fine_value'); ?>
                    </div>
                    <div class="col-6 col-md-2" data-kind-coarse hidden>
                        <label class="form-label" for="coarse_value">Coarse value</label>
                        <select class="form-select<?php echo $invalid('coarse_value'); ?>" id="coarse_value" name="coarse_value">
                            <?php foreach (['low', 'medium', 'high'] as $coarse) { ?>
                                <option value="<?php echo $coarse; ?>" <?php echo $shownCoarse === $coarse ? 'selected' : ''; ?>><?php echo $coarse; ?></option>
                            <?php } ?>
                        </select>
                        <?php echo $fieldError('coarse_value'); ?>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="goal_choice">Goal</label>
                        <select class="form-select<?php echo $invalid('goal_choice'); ?>" id="goal_choice" name="goal_choice">
                            <?php echo p202_setup_options(['event' => 'A new event…'] + $goalOptions, $goalChoice); ?>
                        </select>
                        <?php echo $fieldError('goal_choice'); ?>
                    </div>
                    <div class="col-12 col-md-3" data-p202-show-when="goal_choice=event" data-p202-disable-hidden<?php echo $goalChoice === 'event' ? '' : ' hidden'; ?>>
                        <label class="form-label" for="event_name">Event</label>
                        <input class="form-control<?php echo $invalid('event_name'); ?>" type="text" id="event_name" name="event_name" value="<?php echo $e($shownEvent); ?>" placeholder="purchase" maxlength="255">
                        <?php echo $fieldError('event_name'); ?>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="revenue">Revenue</label>
                        <div class="input-group">
                            <?php if ($currencySymbol !== '' && $symbolLeads) { ?><span class="input-group-text"><?php echo $e($currencySymbol); ?></span><?php } ?>
                            <input class="form-control<?php echo $invalid('revenue'); ?>" type="text" inputmode="decimal" id="revenue" name="revenue" value="<?php echo $e($shownRevenue); ?>">
                            <?php if ($currencySymbol !== '' && !$symbolLeads) { ?><span class="input-group-text"><?php echo $e($currencySymbol); ?></span><?php } ?>
                        </div>
                        <?php echo $fieldError('revenue'); ?>
                    </div>
                </div>
                <div class="p202-form-actions">
                    <?php if ($editRule !== null) { ?><a class="btn btn-link" href="<?php echo $e($self . '?app=' . $rowId); ?>">Cancel</a><?php } ?>
                    <button class="btn btn-primary" type="submit"><?php echo $editRule !== null ? 'Save rule' : 'Add rule'; ?></button>
                </div>
                <p class="form-text mb-0">A rule maps exactly one value. Each fine value and each coarse value can be used once per app. A new event becomes one of this app's goals, reached the first time the app logs it.</p>
            </form>
        <?php } ?>

        <?php if ($mobileApps['defaultRules'] !== []) { ?>
            <details class="p202-disclosure" data-p202-remember="setup-mobile-apps-defaults">
                <summary>Account-wide default rules <span class="p202-disclosure__hint"><?php echo count($mobileApps['defaultRules']); ?> apply to apps with no rule of their own</span></summary>
                <div class="p202-disclosure__body">
                    <div class="p202-table-wrap">
                        <table class="table table-hover p202-table">
                            <thead><tr><th>Kind</th><th>Value</th><th>Goal</th><th class="num">Revenue</th></tr></thead>
                            <tbody>
                            <?php foreach ($mobileApps['defaultRules'] as $rule) { ?>
                                <tr class="text-body-secondary">
                                    <td><?php echo $rule['fine_value'] === null ? 'Coarse' : 'Fine'; ?></td>
                                    <td><?php echo $e($rule['fine_value'] === null ? (string)$rule['coarse_value'] : (string)$rule['fine_value']); ?></td>
                                    <td><?php echo $e($rule['event_name']); ?> <span class="p202-pill">default</span></td>
                                    <td class="num"><?php echo $e($money($rule['revenue'])); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-secondary small">This app's own rule for a value always wins over the account-wide one.</p>
                </div>
            </details>
        <?php } ?>
    </div>
</section>
