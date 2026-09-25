<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps: the app's own goals, as a funnel (plan §5.5, PR 11).
 *
 * Listed in funnel order — the install goal, then each goal after the goals
 * it waits for — and, for Android, with how many installs reached each step
 * (trusted, and the unvouched beside them), read from the report's funnel.
 * The form is 4b's goal form with the app's window: counted from the
 * install. A goal the form cannot show is listed with the command that
 * edits it, never opened here to be rewritten.
 *
 * @var array<string, mixed> $mobileApps
 * @var array<string, mixed> $form
 * @var int $rowId
 * @var bool $isIos
 * @var bool $canManage
 * @var string $self
 * @var callable $e
 * @var callable $fieldError
 * @var callable $invalid
 */

$goals = $mobileApps['goals'];
$funnel = $isIos ? null : ($mobileApps['funnel'] ?? null);
$own = $goals === null ? [] : $goals['own'];
$names = [];
foreach ($own as $g) {
    $names[(int)$g['goal_id']] = (string)$g['name'];
}
// The edited goal: ?goal_edit=N, or the one a refused save posted.
$goalPosted = ($mobileApps['failedForm'] ?? '') === 'goal_save';
$editGoalId = (int)($goalPosted ? ($form['goal_id'] ?? 0) : ($_GET['goal_edit'] ?? 0));
$editGoal = null;
foreach ($own as $g) {
    if ((int)$g['goal_id'] === $editGoalId && $g['archived_at'] === null && is_array($g['definition'])
        && p202_goal_form_fits($g['definition'], 'install')) {
        $editGoal = $g;
    }
}
$gv = p202_goal_form_values($editGoal, $goalPosted ? $form : null);
$ge = $goalPosted ? $mobileApps['fieldErrors'] : [];
$afterOptions = [];
foreach ($own as $g) {
    if ($g['archived_at'] === null && (string)$g['goal_id'] !== $gv['goal_id']) {
        $afterOptions[(string)$g['goal_id']] = (string)$g['name'];
    }
}
$installGoal = null;
foreach ($own as $g) {
    if (($g['builtin'] ?? null) === 'install') {
        $installGoal = (int)$g['goal_id'];
    }
}
$top = $funnel === null ? 0 : max([1, ...array_map(static fn (array $c): int => $c['installs'], $funnel)]);
$gAdvancedSet = ($gv['goal_count'] !== '' && $gv['goal_count'] !== '1') || $gv['goal_repeat'] === 'each' || $gv['goal_within_days'] !== ''
    || $gv['goal_after'] !== '' || $gv['goal_where_prop'] !== ''
    || array_intersect(array_keys($ge), ['goal_count', 'goal_repeat_max', 'goal_within_days', 'goal_after', 'goal_where_value', 'goal_where_op']) !== [];
$liveOwn = count(array_filter($own, static fn (array $g): bool => $g['archived_at'] === null));
?>
<section class="p202-panel" id="goals">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Goals</h2>
        <?php if ($liveOwn > 0) { ?><span class="p202-pill p202-pill--accent"><?php echo $liveOwn . ' ' . ($liveOwn === 1 ? 'goal' : 'goals'); ?></span><?php } ?>
        <p class="p202-panel__sub"><?php echo $isIos
            ? 'The steps after the install this app reports. The SDK evaluates them on the device, and a conversion value above says which one was reached.'
            : 'The steps after the install this app reports, as a funnel. A campaign pays for the ones it attaches, on Setup › Campaigns.'; ?></p>
    </div>
    <div class="p202-panel__body">
        <?php if ($goals === null) { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">This app's goals could not be read just now, so this is not a statement that it has none. Reload the page; if it keeps happening the server log has the reason.</div>
            </div>
        <?php } else { ?>
            <?php if (isset($ge['goal'])) {
                echo p202_flash('bad', $ge['goal']);
            } elseif ($ge !== []) {
                echo p202_flash('bad', 'Nothing was saved. ' . (count($ge) === 1 ? 'One field needs attention; its sentence is under it.' : count($ge) . ' fields need attention; each sentence is under its field.'));
            } ?>
            <?php if ($own === []) { ?>
                <div class="p202-empty mb-3">
                    <i class="bi bi-flag p202-empty__icon"></i>
                    <strong class="p202-empty__title">No goals yet</strong>
                    <div><?php echo $isIos
                        ? 'Add the first step after the install below: a tutorial, a level, a purchase, each on the event the app logs.'
                        : 'Every install reaches the built-in install goal. Add the next step below: a tutorial, a level, a purchase.'; ?></div>
                </div>
            <?php } else { ?>
                <ul class="p202-list mb-3" id="goal-list">
                    <?php foreach ($own as $g) {
                        $gid = (int)$g['goal_id'];
                        $archived = $g['archived_at'] !== null;
                        $builtin = ($g['builtin'] ?? null) !== null;
                        $fits = !$builtin && is_array($g['definition']) && p202_goal_form_fits($g['definition'], 'install');
                        $count = $funnel[$gid] ?? null; ?>
                        <li class="p202-list__item<?php echo $gid === $editGoalId ? ' is-active' : ''; ?>" data-goal-id="<?php echo $gid; ?>">
                            <span class="p202-list__name"><?php echo $e($g['name']); ?></span>
                            <?php if ($archived) { ?>
                                <span class="p202-pill">archived</span>
                            <?php } elseif ($builtin) { ?>
                                <span class="p202-pill p202-pill--accent">built in</span>
                            <?php } ?>
                            <?php if (!$archived && !$builtin && $canManage) { ?>
                                <span class="p202-list__actions">
                                    <?php if ($fits) { ?>
                                        <a class="p202-list__action" href="<?php echo $e($self . '?app=' . $rowId . '&goal_edit=' . $gid . '#goals'); ?>">edit</a>
                                    <?php } ?>
                                    <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="<?php echo $e('Archive the goal "' . $g['name'] . '"? Its outcomes keep their history; it stops evaluating new events.'); ?>">
                                        <?php echo $mobileApps['csrf']; ?>
                                        <input type="hidden" name="action" value="goal_archive">
                                        <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                                        <input type="hidden" name="goal_id" value="<?php echo $gid; ?>">
                                        <button type="submit" class="p202-list__action p202-list__action--danger">archive</button>
                                    </form>
                                </span>
                            <?php } ?>
                            <span class="p202-list__meta"><?php
                                echo $e(p202_app_goal_summary($g, $names));
                                if (!$fits && !$builtin && !$archived) {
                                    echo ' · the form cannot show all of it: edit with <code>p202 goal update ' . $gid . '</code>';
                                }
                            ?></span>
                            <?php if (!$isIos && !$archived && $funnel !== null) {
                                $reached = $count['installs'] ?? 0;
                                $unvouched = $count['unvouched'] ?? 0;
                                $width = (int)round(100 * $reached / $top); ?>
                                <span class="p202-list__children">
                                    <span class="d-block small"><?php echo number_format($reached); ?> trusted <?php echo $reached === 1 ? 'install' : 'installs'; ?> reached it<?php echo $unvouched > 0 ? ' · ' . number_format($unvouched) . ' unvouched' : ''; ?></span>
                                    <span class="progress" role="progressbar" aria-label="<?php echo $e($g['name'] . ': installs that reached it'); ?>" aria-valuenow="<?php echo $reached; ?>" aria-valuemin="0" aria-valuemax="<?php echo $top; ?>">
                                        <span class="progress-bar" style="width: <?php echo $width; ?>%"></span>
                                    </span>
                                </span>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
                <?php if (!$isIos && $funnel === null) { ?>
                    <p class="text-secondary small">How many installs reached each goal could not be read just now; Analyze › Mobile Apps has the funnel.</p>
                <?php } ?>
            <?php } ?>

            <?php if ($goals['account'] !== []) { ?>
                <details class="p202-disclosure mb-3" data-p202-remember="setup-mobile-apps-account-goals">
                    <summary>Account-wide goals <span class="p202-disclosure__hint"><?php echo count($goals['account']); ?> every app of the account shares</span></summary>
                    <div class="p202-disclosure__body">
                        <ul class="p202-list">
                            <?php foreach ($goals['account'] as $g) { ?>
                                <li class="p202-list__item">
                                    <span class="p202-list__name"><?php echo $e($g['name']); ?></span>
                                    <span class="p202-list__meta"><?php echo $e(p202_app_goal_summary($g, [])); ?> · edit with <code>p202 goal update <?php echo (int)$g['goal_id']; ?></code></span>
                                </li>
                            <?php } ?>
                        </ul>
                    </div>
                </details>
            <?php } ?>

            <?php if ($canManage) { ?>
                <form method="post" action="<?php echo $e($self . '#goals'); ?>" id="goal-form">
                    <?php echo $mobileApps['csrf']; ?>
                    <input type="hidden" name="action" value="goal_save">
                    <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                    <input type="hidden" name="goal_id" value="<?php echo $e($gv['goal_id']); ?>">
                    <h3 class="h6 mb-3"><?php echo $gv['goal_id'] !== '' ? 'Edit the goal' : 'Add a goal'; ?></h3>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="goal_name">Goal name</label>
                            <input type="text" class="form-control<?php echo isset($ge['goal_name']) ? ' is-invalid' : ''; ?>" id="goal_name" name="goal_name" value="<?php echo $e($gv['goal_name']); ?>" maxlength="100" placeholder="Tutorial complete" required>
                            <?php echo isset($ge['goal_name']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_name']) . '</div>' : ''; ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="goal_event">Event</label>
                            <input type="text" class="form-control font-monospace<?php echo isset($ge['goal_event']) ? ' is-invalid' : ''; ?>" id="goal_event" name="goal_event" value="<?php echo $e($gv['goal_event']); ?>" maxlength="64" placeholder="tutorial_done" pattern="[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}" required>
                            <div class="form-text">The name the app logs the event under. Letters, digits and <code>_ . : -</code>.</div>
                            <?php echo isset($ge['goal_event']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_event']) . '</div>' : ''; ?>
                        </div>
                    </div>

                    <fieldset class="my-3">
                        <legend class="form-label">Worth</legend>
                        <?php foreach (['fixed' => 'A fixed amount', 'property' => 'The amount the event reports', 'none' => 'Nothing: track it only'] as $opt => $label) { ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="goal_value" id="goal_value_<?php echo $opt; ?>" value="<?php echo $opt; ?>"<?php echo $gv['goal_value'] === $opt ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="goal_value_<?php echo $opt; ?>"><?php echo $label; ?></label>
                            </div>
                        <?php } ?>
                        <div class="form-text">A fixed amount by default. What an event reports is paid only when Settings › Advanced trusts the app's revenue.</div>
                    </fieldset>
                    <div class="mb-3" data-p202-show-when="goal_value=fixed" data-p202-disable-hidden<?php echo $gv['goal_value'] === 'fixed' ? '' : ' hidden'; ?>>
                        <label class="form-label" for="goal_amount">Amount</label>
                        <input type="text" inputmode="decimal" class="form-control<?php echo isset($ge['goal_amount']) ? ' is-invalid' : ''; ?>" id="goal_amount" name="goal_amount" value="<?php echo $e($gv['goal_amount']); ?>" placeholder="4.00">
                        <?php echo isset($ge['goal_amount']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_amount']) . '</div>' : ''; ?>
                    </div>

                    <details class="p202-disclosure mb-3" data-p202-remember="setup-mobile-apps-goal-advanced"<?php echo $gAdvancedSet ? ' open' : ''; ?>>
                        <summary>Advanced <span class="p202-disclosure__hint">a funnel step, the Nth event, repeats, a window, a condition</span></summary>
                        <div class="p202-disclosure__body">
                            <?php if ($afterOptions !== []) { ?>
                                <div class="mb-3">
                                    <label class="form-label" for="goal_after">Only after</label>
                                    <select class="form-select<?php echo isset($ge['goal_after']) ? ' is-invalid' : ''; ?>" id="goal_after" name="goal_after">
                                        <?php echo p202_setup_options($afterOptions, $gv['goal_after'], 'No other goal first'); ?>
                                    </select>
                                    <div class="form-text">A funnel step: purchase only once the tutorial is done.<?php echo $installGoal !== null ? ' Every install reaches the install goal, so "after install" adds nothing.' : ''; ?></div>
                                    <?php echo isset($ge['goal_after']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_after']) . '</div>' : ''; ?>
                                </div>
                            <?php } ?>
                            <div class="mb-3">
                                <label class="form-label" for="goal_count">Reached on event number</label>
                                <input type="text" inputmode="numeric" class="form-control<?php echo isset($ge['goal_count']) ? ' is-invalid' : ''; ?>" id="goal_count" name="goal_count" value="<?php echo $e($gv['goal_count']); ?>">
                                <div class="form-text">1 by default: the first matching event reaches it. 3 would be the third purchase.</div>
                                <?php echo isset($ge['goal_count']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_count']) . '</div>' : ''; ?>
                            </div>
                            <fieldset class="mb-3">
                                <legend class="form-label">How often</legend>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="goal_repeat" id="goal_repeat_once" value="once"<?php echo $gv['goal_repeat'] !== 'each' ? ' checked' : ''; ?>>
                                    <label class="form-check-label" for="goal_repeat_once">Once per install</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="goal_repeat" id="goal_repeat_each" value="each"<?php echo $gv['goal_repeat'] === 'each' ? ' checked' : ''; ?>>
                                    <label class="form-check-label" for="goal_repeat_each">Every time</label>
                                </div>
                                <div class="form-text">Once by default. Every time counts each renewal or repeat purchase.</div>
                            </fieldset>
                            <div class="mb-3" data-p202-show-when="goal_repeat=each" data-p202-disable-hidden<?php echo $gv['goal_repeat'] === 'each' ? '' : ' hidden'; ?>>
                                <label class="form-label" for="goal_repeat_max">At most</label>
                                <input type="text" inputmode="numeric" class="form-control<?php echo isset($ge['goal_repeat_max']) ? ' is-invalid' : ''; ?>" id="goal_repeat_max" name="goal_repeat_max" value="<?php echo $e($gv['goal_repeat_max']); ?>" placeholder="no limit">
                                <?php echo isset($ge['goal_repeat_max']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_repeat_max']) . '</div>' : ''; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="goal_within_days">Within days of the install</label>
                                <input type="text" inputmode="numeric" class="form-control<?php echo isset($ge['goal_within_days']) ? ' is-invalid' : ''; ?>" id="goal_within_days" name="goal_within_days" value="<?php echo $e($gv['goal_within_days']); ?>" placeholder="no limit">
                                <div class="form-text">Empty by default: any time after the install counts.</div>
                                <?php echo isset($ge['goal_within_days']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_within_days']) . '</div>' : ''; ?>
                            </div>
                            <fieldset class="mb-1">
                                <legend class="form-label">Only when a property</legend>
                                <div class="row g-2">
                                    <div class="col-12 col-sm-4">
                                        <label class="visually-hidden" for="goal_where_prop">Property</label>
                                        <input type="text" class="form-control font-monospace" id="goal_where_prop" name="goal_where_prop" value="<?php echo $e($gv['goal_where_prop']); ?>" placeholder="level">
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <label class="visually-hidden" for="goal_where_op">Comparison</label>
                                        <select class="form-select<?php echo isset($ge['goal_where_op']) ? ' is-invalid' : ''; ?>" id="goal_where_op" name="goal_where_op">
                                            <?php echo p202_setup_options(P202_GOAL_OPS, $gv['goal_where_op'] !== '' ? $gv['goal_where_op'] : 'eq'); ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <label class="visually-hidden" for="goal_where_value">Value</label>
                                        <input type="text" class="form-control<?php echo isset($ge['goal_where_value']) ? ' is-invalid' : ''; ?>" id="goal_where_value" name="goal_where_value" value="<?php echo $e($gv['goal_where_value']); ?>" placeholder="3">
                                        <?php echo isset($ge['goal_where_value']) ? '<div class="invalid-feedback d-block">' . $e($ge['goal_where_value']) . '</div>' : ''; ?>
                                    </div>
                                </div>
                                <div class="form-text">Empty by default: every event of that name counts. A number is compared as a number.</div>
                            </fieldset>
                        </div>
                    </details>

                    <div class="p202-form-actions">
                        <?php if ($gv['goal_id'] !== '') { ?>
                            <a class="btn btn-link" href="<?php echo $e($self . '?app=' . $rowId . '#goals'); ?>">Cancel</a>
                        <?php } ?>
                        <button type="submit" class="btn btn-secondary" id="saveGoal"><?php echo $gv['goal_id'] !== '' ? 'Save goal' : 'Add goal'; ?></button>
                    </div>
                </form>
            <?php } ?>
        <?php } ?>
    </div>
</section>
