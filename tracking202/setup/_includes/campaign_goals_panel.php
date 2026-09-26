<?php

declare(strict_types=1);

/**
 * Setup › Campaigns, the Goals panel (plan §2.2, PR 4b): shown while a
 * campaign is being edited, under its form. The list is the campaign's own
 * goals and the shared ones it pays for; the form adds or edits one, with
 * the common shape open (a name, an event, what it is worth) and everything
 * else under a closed Advanced disclosure (UI standard, rule 1).
 *
 * Components are the kit's (202-account/ui-kit.php): p202-panel, p202-list,
 * p202-pill, p202-empty and p202-disclosure, parts included (error pattern
 * #19).
 *
 * @var array<string, mixed> $goalPanel built by the page: campaign_id, self,
 *      token, goals (own, attached), form (values), errors, editing (bool),
 *      payout_mode
 */

$gp = $goalPanel;
$gv = $gp['form'];
$ge = $gp['errors'];
$gSelf = $gp['self'] . '?edit_aff_campaign_id=' . (int) $gp['campaign_id'];
$ownGoals = $gp['goals']['own'];
$paidCount = 0;
foreach (array_merge($ownGoals, $gp['goals']['attached']) as $g) {
	if (p202_goal_summary($g, (int) $gp['campaign_id'])['paid'] && $g['archived_at'] === null) {
		$paidCount++;
	}
}
$afterOptions = [];
foreach ($ownGoals as $g) {
	if ($g['archived_at'] === null && (string) $g['goal_id'] !== $gv['goal_id']) {
		$afterOptions[(string) $g['goal_id']] = (string) $g['name'];
	}
}
$gAdvancedSet = ($gv['goal_count'] !== '' && $gv['goal_count'] !== '1') || $gv['goal_repeat'] === 'each' || $gv['goal_within_days'] !== ''
	|| $gv['goal_after'] !== '' || $gv['goal_where_prop'] !== '' || $gv['goal_payout'] !== '' || $gv['goal_notify'] !== '1'
	|| p202_setup_invalid($ge, 'goal_count', 'goal_repeat_max', 'goal_within_days', 'goal_after', 'goal_where_value', 'goal_where_op', 'goal_payout') !== '';
?>
<section class="p202-panel mt-4" id="campaign-goals">
	<div class="p202-panel__head">
		<h2 class="p202-panel__title">Goals</h2>
		<?php $liveGoals = count(array_filter($ownGoals, static fn (array $g): bool => $g['archived_at'] === null));
		if ($liveGoals > 0) { ?>
			<span class="p202-pill p202-pill--accent"><?php echo $liveGoals . ' ' . ($liveGoals === 1 ? 'goal' : 'goals'); ?></span>
		<?php } ?>
		<p class="p202-panel__sub">The steps after the click that this campaign pays for, each on its own event.</p>
	</div>
	<div class="p202-panel__body">
		<?php if (isset($ge['goal'])) {
			echo p202_flash('bad', p202_setup_error_text($ge['goal']));
		} elseif ($ge !== []) {
			echo p202_flash('bad', 'Nothing was saved. ' . (count($ge) === 1 ? 'One field needs attention; its sentence is under it.' : count($ge) . ' fields need attention; each sentence is under its field.'));
		} ?>
		<?php if ($paidCount > 1 && $gp['payout_mode'] !== 'accumulate') { ?>
			<?php echo p202_flash('warn', 'This campaign keeps only a click\'s latest conversion, so a click that reaches two paid goals is worth the second one alone. To add them up, set "When a click converts more than once" to "Add them up" under Advanced above.'); ?>
		<?php } ?>

		<?php if ($ownGoals === [] && $gp['goals']['attached'] === []) { ?>
			<div class="p202-empty mb-3">
				<i class="bi bi-flag p202-empty__icon"></i>
				<strong class="p202-empty__title">No goals yet</strong>
				<div>This campaign pays per conversion, as it always has. Add a goal to pay for the steps of a funnel — a signup, a sale, an upsell — each reported as an event.</div>
			</div>
		<?php } else { ?>
			<ul class="p202-list mb-3" id="goal-list">
				<?php foreach ($ownGoals as $g) {
					$sum = p202_goal_summary($g, (int) $gp['campaign_id']);
					$archived = $g['archived_at'] !== null;
					$fits = is_array($g['definition']) && p202_goal_form_fits($g['definition']); ?>
					<li class="p202-list__item<?php echo (string) $g['goal_id'] === $gv['goal_id'] ? ' is-active' : ''; ?>" data-goal-id="<?php echo (int) $g['goal_id']; ?>">
						<span class="p202-list__name"><?php echo p202_setup_e($g['name']); ?></span>
						<?php if ($archived) { ?>
							<span class="p202-pill">archived</span>
						<?php } elseif ($sum['paid']) { ?>
							<span class="p202-pill p202-pill--good"><?php echo p202_setup_e($sum['payout'] ?? $sum['worth']); ?></span>
						<?php } else { ?>
							<span class="p202-pill">tracked</span>
						<?php } ?>
						<?php if (!$archived) { ?>
							<span class="p202-list__actions">
								<?php if ($fits) { ?>
									<a class="p202-list__action" href="<?php echo p202_setup_e($gSelf . '&edit_goal_id=' . (int) $g['goal_id'] . '#campaign-goals'); ?>">edit</a>
								<?php } ?>
								<form method="post" action="<?php echo p202_setup_e($gSelf); ?>" class="d-inline" data-p202-confirm="<?php echo p202_setup_e('Archive the goal "' . $g['name'] . '"? Its outcomes and conversions keep their history; it stops evaluating new events.'); ?>">
									<?php echo p202_setup_token_field($gp['token']); ?>
									<input type="hidden" name="goal_action" value="archive">
									<input type="hidden" name="goal_id" value="<?php echo (int) $g['goal_id']; ?>">
									<button type="submit" class="p202-list__action p202-list__action--danger">archive</button>
								</form>
							</span>
						<?php } ?>
						<span class="p202-list__meta">on <code><?php echo p202_setup_e($sum['event']); ?></code> · <?php echo p202_setup_e($sum['worth']); ?><?php
							echo $sum['paid'] ? ($sum['notify'] ? ' · tells the traffic source' : ' · the traffic source is not told') : '';
							echo ' · reached ' . (int) ($g['live_outcomes'] ?? 0) . ' ' . ((int) ($g['live_outcomes'] ?? 0) === 1 ? 'time' : 'times');
							echo !$fits && !$archived ? ' · edit with p202 goal update ' . (int) $g['goal_id'] : ''; ?></span>
					</li>
				<?php } ?>
				<?php foreach ($gp['goals']['attached'] as $g) {
					$sum = p202_goal_summary($g, (int) $gp['campaign_id']); ?>
					<li class="p202-list__item" data-goal-id="<?php echo (int) $g['goal_id']; ?>">
						<span class="p202-list__name"><?php echo p202_setup_e($g['name']); ?></span>
						<span class="p202-pill p202-pill--good"><?php echo p202_setup_e($sum['payout'] ?? $sum['worth']); ?></span>
						<span class="p202-list__meta">shared <?php echo p202_setup_e($g['scope']); ?> goal on <code><?php echo p202_setup_e($sum['event']); ?></code> · paid here</span>
					</li>
				<?php } ?>
			</ul>
		<?php } ?>

		<form method="post" action="<?php echo p202_setup_e($gSelf . ($gp['editing'] ? '&edit_goal_id=' . (int) $gv['goal_id'] : '') . '#campaign-goals'); ?>" id="goal-form">
			<?php echo p202_setup_token_field($gp['token']); ?>
			<input type="hidden" name="goal_action" value="save">
			<input type="hidden" name="goal_id" value="<?php echo p202_setup_e($gv['goal_id']); ?>">
			<h3 class="h6 mb-3"><?php echo $gp['editing'] ? 'Edit the goal' : 'Add a goal'; ?></h3>

			<div class="mb-3">
				<label class="form-label" for="goal_name">Goal name</label>
				<input type="text" class="form-control<?php echo p202_setup_invalid($ge, 'goal_name'); ?>" id="goal_name" name="goal_name" value="<?php echo p202_setup_e($gv['goal_name']); ?>" maxlength="100" placeholder="Sale" required>
				<?php echo p202_setup_feedback($ge, 'goal_name'); ?>
			</div>

			<div class="mb-3">
				<label class="form-label" for="goal_event">Event</label>
				<input type="text" class="form-control font-monospace<?php echo p202_setup_invalid($ge, 'goal_event'); ?>" id="goal_event" name="goal_event" value="<?php echo p202_setup_e($gv['goal_event']); ?>" maxlength="64" placeholder="sale" pattern="[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}" required>
				<div class="form-text">The name your pixel, postback or page reports: <code>event=sale</code> on the postback, or <code>p202.track('sale')</code> on the page. Letters, digits and <code>_ . : -</code>.</div>
				<?php echo p202_setup_feedback($ge, 'goal_event'); ?>
			</div>

			<fieldset class="mb-3">
				<legend class="form-label">Worth</legend>
				<?php foreach (['fixed' => 'A fixed amount', 'property' => 'The amount the event reports', 'none' => 'Nothing: track it only'] as $opt => $label) { ?>
					<div class="form-check">
						<input class="form-check-input" type="radio" name="goal_value" id="goal_value_<?php echo $opt; ?>" value="<?php echo $opt; ?>"<?php echo $gv['goal_value'] === $opt ? ' checked' : ''; ?>>
						<label class="form-check-label" for="goal_value_<?php echo $opt; ?>"><?php echo $label; ?></label>
					</div>
				<?php } ?>
				<div class="form-text">The amount an event reports is <code>amount=</code> on a pixel or postback, or <code>revenue</code> in the API. One from a page script is recorded but never paid.</div>
				<?php echo p202_setup_feedback($ge, 'goal_value'); ?>
			</fieldset>

			<div class="mb-3" data-p202-show-when="goal_value=fixed" data-p202-disable-hidden<?php echo $gv['goal_value'] === 'fixed' ? '' : ' hidden'; ?>>
				<label class="form-label" for="goal_amount">Amount</label>
				<div class="input-group">
					<span class="input-group-text">$</span>
					<input type="text" inputmode="decimal" class="form-control<?php echo p202_setup_invalid($ge, 'goal_amount'); ?>" id="goal_amount" name="goal_amount" value="<?php echo p202_setup_e($gv['goal_amount']); ?>" placeholder="4.00">
				</div>
				<?php echo p202_setup_feedback($ge, 'goal_amount'); ?>
			</div>

			<details class="p202-disclosure mb-3" data-p202-remember="setup-campaign-goal-advanced"<?php echo $gAdvancedSet ? ' open' : ''; ?>>
				<summary>Advanced <span class="p202-disclosure__hint">the Nth event, repeats, a window, a condition, a payout override</span></summary>
				<div class="p202-disclosure__body">
					<div class="mb-3">
						<label class="form-label" for="goal_count">Reached on event number</label>
						<input type="text" inputmode="numeric" class="form-control<?php echo p202_setup_invalid($ge, 'goal_count'); ?>" id="goal_count" name="goal_count" value="<?php echo p202_setup_e($gv['goal_count']); ?>">
						<div class="form-text">1 by default: the first matching event reaches it. 3 would be the third purchase.</div>
						<?php echo p202_setup_feedback($ge, 'goal_count'); ?>
					</div>

					<fieldset class="mb-3">
						<legend class="form-label">How often</legend>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="goal_repeat" id="goal_repeat_once" value="once"<?php echo $gv['goal_repeat'] !== 'each' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="goal_repeat_once">Once per click</label>
						</div>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="goal_repeat" id="goal_repeat_each" value="each"<?php echo $gv['goal_repeat'] === 'each' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="goal_repeat_each">Every time</label>
						</div>
						<div class="form-text">Once by default. Every time pays each renewal or repeat purchase.</div>
					</fieldset>
					<div class="mb-3" data-p202-show-when="goal_repeat=each" data-p202-disable-hidden<?php echo $gv['goal_repeat'] === 'each' ? '' : ' hidden'; ?>>
						<label class="form-label" for="goal_repeat_max">At most</label>
						<input type="text" inputmode="numeric" class="form-control<?php echo p202_setup_invalid($ge, 'goal_repeat_max'); ?>" id="goal_repeat_max" name="goal_repeat_max" value="<?php echo p202_setup_e($gv['goal_repeat_max']); ?>" placeholder="no limit">
						<?php echo p202_setup_feedback($ge, 'goal_repeat_max'); ?>
					</div>

					<div class="mb-3">
						<label class="form-label" for="goal_within_days">Within days of the click</label>
						<input type="text" inputmode="numeric" class="form-control<?php echo p202_setup_invalid($ge, 'goal_within_days'); ?>" id="goal_within_days" name="goal_within_days" value="<?php echo p202_setup_e($gv['goal_within_days']); ?>" placeholder="no limit">
						<div class="form-text">Empty by default: any time after the click counts.</div>
						<?php echo p202_setup_feedback($ge, 'goal_within_days'); ?>
					</div>

					<?php if ($afterOptions !== []) { ?>
						<div class="mb-3">
							<label class="form-label" for="goal_after">Only after</label>
							<select class="form-select<?php echo p202_setup_invalid($ge, 'goal_after'); ?>" id="goal_after" name="goal_after">
								<?php echo p202_setup_options($afterOptions, $gv['goal_after'], 'No other goal first'); ?>
							</select>
							<div class="form-text">A funnel step: an upsell that counts only once the sale is reached.</div>
							<?php echo p202_setup_feedback($ge, 'goal_after'); ?>
						</div>
					<?php } ?>

					<fieldset class="mb-3">
						<legend class="form-label">Only when a property</legend>
						<div class="row g-2">
							<div class="col-12 col-sm-3">
								<label class="visually-hidden" for="goal_where_prop">Property</label>
								<input type="text" class="form-control font-monospace" id="goal_where_prop" name="goal_where_prop" value="<?php echo p202_setup_e($gv['goal_where_prop']); ?>" placeholder="plan">
							</div>
							<div class="col-12 col-sm-3">
								<label class="visually-hidden" for="goal_where_op">Comparison</label>
								<select class="form-select<?php echo p202_setup_invalid($ge, 'goal_where_op'); ?>" id="goal_where_op" name="goal_where_op">
									<?php echo p202_setup_options(P202_GOAL_OPS, $gv['goal_where_op'] !== '' ? $gv['goal_where_op'] : 'eq'); ?>
								</select>
							</div>
							<div class="col-12 col-sm-3">
								<label class="visually-hidden" for="goal_where_value">Value</label>
								<input type="text" class="form-control<?php echo p202_setup_invalid($ge, 'goal_where_value'); ?>" id="goal_where_value" name="goal_where_value" value="<?php echo p202_setup_e($gv['goal_where_value']); ?>" placeholder="pro">
								<?php echo p202_setup_feedback($ge, 'goal_where_value'); ?>
							</div>
							<div class="col-12 col-sm-3">
								<label class="visually-hidden" for="goal_where_type">Compare as</label>
								<select class="form-select<?php echo p202_setup_invalid($ge, 'goal_where_type'); ?>" id="goal_where_type" name="goal_where_type" title="Compare as">
									<?php echo p202_setup_options(P202_GOAL_VALUE_TYPES, $gv['goal_where_type'] !== '' ? $gv['goal_where_type'] : 'auto'); ?>
								</select>
								<?php echo p202_setup_feedback($ge, 'goal_where_type'); ?>
							</div>
						</div>
						<div class="form-text">Empty by default: every event of that name counts. A property is one the event carries (<code>event_props</code> on a postback). The last box says how the value is read (Automatic: a number as a number, anything else as text); the text <code>123</code> and the number <code>123</code> are different values, as are <code>true</code> and the text "true".</div>
					</fieldset>

					<div class="mb-3">
						<label class="form-label" for="goal_payout">Payout on this campaign</label>
						<div class="input-group">
							<span class="input-group-text">$</span>
							<input type="text" inputmode="decimal" class="form-control<?php echo p202_setup_invalid($ge, 'goal_payout'); ?>" id="goal_payout" name="goal_payout" value="<?php echo p202_setup_e($gv['goal_payout']); ?>" placeholder="the goal's worth">
						</div>
						<div class="form-text">Empty by default: the goal pays its worth. An amount here pays that instead, even for a tracked goal.</div>
						<?php echo p202_setup_feedback($ge, 'goal_payout'); ?>
					</div>

					<div class="form-check mb-1">
						<input type="hidden" name="goal_notify" value="0">
						<input class="form-check-input" type="checkbox" name="goal_notify" id="goal_notify" value="1"<?php echo $gv['goal_notify'] === '1' ? ' checked' : ''; ?>>
						<label class="form-check-label" for="goal_notify">Tell the traffic source when a paid goal is reached</label>
					</div>
					<div class="form-text">On by default: the traffic source's postback is sent with <code>[[p202_goal]]</code> and <code>[[p202_goal_value]]</code> filled in.</div>
				</div>
			</details>

			<div class="p202-form-actions">
				<?php if ($gp['editing']) { ?>
					<a class="btn btn-link" href="<?php echo p202_setup_e($gSelf . '#campaign-goals'); ?>">Cancel</a>
				<?php } ?>
				<button type="submit" class="btn btn-primary" id="saveGoal"><?php echo $gp['editing'] ? 'Save goal' : 'Add goal'; ?></button>
			</div>
		</form>
	</div>
</section>
