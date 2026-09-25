<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

/*
 * Account › VIP Perks profile, on the v2 shell.
 *
 * The questions come from the Prosper202 VIP Perks service and the answers go
 * back to it; each question's id is the field name, answered Yes or No, as
 * the classic page's AJAX post sent them. The classic page rendered one
 * <form id="survey-form"> per question group and serialized only the first,
 * so every group after the first was never sent. This is one form, posted to
 * this page with the session token (which is removed before the answers
 * leave this install), and a question left unanswered is refused here, by
 * name, before anything is sent anywhere.
 */

if (!$userObj->hasPermission('access_to_vip_perks')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

$user_data = get_user_data_feedback($_SESSION['user_id']);
$install_hash = (string)($user_data['install_hash'] ?? '');
$survey_data = $install_hash !== '' ? getSurveyData($install_hash) : null;
$surveyAvailable = is_array($survey_data) && isset($survey_data['question_groups'], $survey_data['questions'])
	&& is_array($survey_data['question_groups']) && is_array($survey_data['questions']);

/** @var array<string, string> question id => sentence */
$fieldErrors = [];
$pageFlashes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/vip-perks.php');
	}
	if (!$surveyAvailable) {
		$pageFlashes[] = ['kind' => 'bad', 'text' => 'The VIP Perks service could not be reached, so your answers were not sent. Try again in a few minutes.'];
	} else {
		$answers = [];
		foreach ($survey_data['questions'] as $question) {
			$id = (string)$question['id'];
			$answer = (string)($_POST[$id] ?? '');
			if ($answer !== 'Yes' && $answer !== 'No') {
				$fieldErrors[$id] = 'Answer Yes or No.';
				continue;
			}
			$answers[$id] = $answer;
		}
		if (!$fieldErrors) {
			$response = updateSurveyData($install_hash, $answers);
			if (is_array($response) && !empty($response['updated'])) {
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$db->query("UPDATE 202_users SET modal_status='1', vip_perks_status='0' WHERE user_id='" . $mysql['user_id'] . "'");
				p202_account_flash('ok', 'Thank you. Your VIP Perks profile is saved.');
				p202_account_redirect('202-account/vip-perks.php');
			}
			$pageFlashes[] = ['kind' => 'bad', 'text' => 'The VIP Perks service did not accept the answers. Nothing was saved; try again.'];
		} else {
			$pageFlashes[] = ['kind' => 'bad', 'text' => count($fieldErrors) === 1 ? 'One question is not answered yet.' : count($fieldErrors) . ' questions are not answered yet.'];
		}
	}
}

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

template_top('VIP Perks Profile');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-gift"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">VIP Perks profile</h1>
		<p class="p202-page-header__desc">Tell us how you market, and Prosper202 VIP Perks matches you with private campaigns, better payouts, discounts and offers that fit.</p>
	</div>
</div>

<?php echo p202_account_render_flashes($pageFlashes); ?>

<?php if (!$surveyAvailable) { ?>
	<div class="p202-empty">
		<i class="bi bi-cloud-slash p202-empty__icon"></i>
		<strong class="p202-empty__title">The questions could not be loaded</strong>
		<div>They come from the Prosper202 VIP Perks service, which did not answer just now.</div>
		<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-account/vip-perks.php'); ?>">Try again</a></div>
	</div>
<?php } else { ?>
	<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/vip-perks.php'); ?>" id="survey-form">
		<?php echo p202_account_token_field(); ?>
		<div class="row g-4">
			<?php foreach ($survey_data['question_groups'] as $group) {
				$questions = array_values(array_filter($survey_data['questions'], static fn ($q): bool => is_array($q) && ($q['group_id'] ?? null) == ($group['id'] ?? null)));
				if (!$questions) {
					continue;
				} ?>
				<div class="col-12 col-lg-6">
					<section class="p202-panel h-100">
						<div class="p202-panel__head"><h2 class="p202-panel__title"><?php echo $e($group['title'] ?? ''); ?></h2></div>
						<div class="p202-panel__body">
							<?php foreach ($questions as $question) {
								$id = (string)$question['id'];
								$posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST[$id] ?? '') : '';
								$answer = $posted !== '' ? $posted : ((string)($question['answer'] ?? '') === '' ? '' : ((string)$question['answer'] === 'Yes' ? 'Yes' : 'No'));
								$isNew = $answer === '' && !empty($question['highlighted']);
								$invalid = isset($fieldErrors[$id]);
								?>
								<fieldset class="mb-3">
									<legend class="form-label fs-6 mb-1"><?php echo $e($question['name'] ?? ''); ?><?php if ($isNew) { ?> <span class="p202-pill p202-pill--accent">new</span><?php } ?></legend>
									<div class="d-flex gap-3">
										<div class="form-check"><input class="form-check-input<?php echo $invalid ? ' is-invalid' : ''; ?>" type="radio" name="<?php echo $e($id); ?>" id="q<?php echo $e($id); ?>-yes" value="Yes" required<?php echo $answer === 'Yes' ? ' checked' : ''; ?>><label class="form-check-label" for="q<?php echo $e($id); ?>-yes">Yes</label></div>
										<div class="form-check"><input class="form-check-input<?php echo $invalid ? ' is-invalid' : ''; ?>" type="radio" name="<?php echo $e($id); ?>" id="q<?php echo $e($id); ?>-no" value="No"<?php echo $answer === 'No' ? ' checked' : ''; ?>><label class="form-check-label" for="q<?php echo $e($id); ?>-no">No</label></div>
									</div>
									<?php echo p202_account_field_error($fieldErrors, $id); ?>
								</fieldset>
							<?php } ?>
						</div>
					</section>
				</div>
			<?php } ?>
		</div>
		<div class="p202-form-actions">
			<button class="btn btn-primary" type="submit" id="survey-form-submit">Save answers</button>
		</div>
	</form>
<?php } ?>

<?php template_bottom();
