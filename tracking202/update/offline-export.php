<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
require_once __DIR__ . '/_includes/update_ui.php';

use Prosper202\Conversion\OfflineConversionExport;

AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

/*
 * Export Offline Conversions: the conversions on clicks from Google Ads
 * (gclid) or Microsoft Advertising (msclkid), as the CSV that platform's
 * offline conversion import takes (OfflineConversionExport, which
 * GET /api/v3/conversions/offline-export answers with too). A GET form:
 * "Check" says how many conversions go in and what is left out, and the
 * download is a link with the same values. Nothing is written.
 */

AUTH::set_timezone($_SESSION['user_timezone'] ?? date_default_timezone_get());

$userId = (int) $_SESSION['user_id'];
$base = get_absolute_url();
$self = $base . 'tracking202/update/offline-export.php';
$mayExport = $userObj->hasPermission('access_to_campaign_data');
$platforms = ['google' => 'Google Ads (gclid)', 'microsoft' => 'Microsoft Advertising (msclkid)'];

$currencyRow = p202_setup_rows(
    $db,
    "SELECT user_account_currency FROM 202_users_pref WHERE user_id = '" . $userId . "' LIMIT 1"
);
$accountCurrency = strtoupper((string) ($currencyRow[0]['user_account_currency'] ?? 'USD'));

$query = static fn (string $key): ?string => is_string($_GET[$key] ?? null) ? $_GET[$key] : null;
$currency = $query('currency');
$form = [
    'platform' => $query('platform') ?? 'google',
    'conversion_name' => $query('conversion_name') ?? '',
    'currency' => $currency === null || $currency === '' ? $accountCurrency : strtoupper($currency),
    'from' => $query('from') ?? date('Y-m-d', strtotime('-29 days')),
    'to' => $query('to') ?? date('Y-m-d'),
];
$errors = [];
$result = null;

if ((isset($_GET['check']) || isset($_GET['download'])) && $mayExport) {
    if (!isset($platforms[$form['platform']])) {
        $errors['platform'] = 'Choose Google Ads or Microsoft Advertising.';
    }
    $nameProblem = OfflineConversionExport::nameProblem($form['conversion_name']);
    if ($nameProblem !== null) {
        $errors['conversion_name'] = 'The conversion name ' . $nameProblem . '.';
    }
    if (preg_match('/^[A-Z]{3}$/D', $form['currency']) !== 1) {
        $errors['currency'] = 'The currency is a three-letter code such as USD.';
    }
    $zone = new DateTimeZone(date_default_timezone_get());
    $fromDay = DateTimeImmutable::createFromFormat('!Y-m-d', $form['from'], $zone);
    $toDay = DateTimeImmutable::createFromFormat('!Y-m-d', $form['to'], $zone);
    if ($fromDay === false || $fromDay->format('Y-m-d') !== $form['from']) {
        $errors['from'] = 'The first day is not a date.';
    }
    if ($toDay === false || $toDay->format('Y-m-d') !== $form['to']) {
        $errors['to'] = 'The last day is not a date.';
    }
    if (!isset($errors['from']) && !isset($errors['to']) && $fromDay > $toDay) {
        $errors['to'] = 'The last day is before the first.';
    }
    if ($errors === [] && $fromDay instanceof DateTimeImmutable && $toDay instanceof DateTimeImmutable) {
        $from = $fromDay->getTimestamp();
        $to = $toDay->modify('+1 day')->getTimestamp() - 1;
        $exporter = new OfflineConversionExport(new \Prosper202\Database\Connection($db));
        $result = $exporter->export(
            $userId,
            $form['platform'],
            $from,
            $to,
            $form['conversion_name'],
            $form['currency']
        );
        if (isset($_GET['download']) && $result['rows'] > 0) {
            header('Content-Type: text/csv; charset=utf-8');
            $filename = OfflineConversionExport::filename($form['platform'], $from, $to, $zone);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($result['csv']));
            header('Cache-Control: no-store');
            echo $result['csv'];
            exit;
        }
    }
}

$download = $self . '?' . http_build_query(['download' => 1] + $form);
$count = static fn (int $n): string => $n . ' ' . ($n === 1 ? 'conversion' : 'conversions');
$clickId = $form['platform'] === 'microsoft' ? 'msclkid' : 'gclid';
$leftOut = [];
if ($result !== null) {
    $skipped = $result['skipped'];
    if ($skipped['reversed'] > 0) {
        $leftOut[] = $skipped['reversed'] . ' refunded to nothing';
    }
    if ($skipped['click_too_old'] > 0) {
        $leftOut[] = $skipped['click_too_old'] . ' on a click more than 90 days older than the conversion,'
            . ' whose click id the platform no longer has';
    }
    if ($skipped['malformed_click_id'] > 0) {
        $leftOut[] = $skipped['malformed_click_id'] . ' whose click id is not one the platform issues';
    }
}

template_top('Export Offline Conversions');

echo p202_update_header(
    'bi-cloud-arrow-up',
    'Export offline conversions',
    'Send your conversions back to Google Ads or Microsoft Advertising: a CSV of the conversions on clicks from'
    . ' that platform, to upload as offline conversions.'
);

if (!$mayExport) {
    echo p202_flash('bad', 'This file is made of your conversions\' values, which your role does not show'
        . ' (access_to_campaign_data). Ask an administrator for that permission.');
}
if ($errors !== []) {
    echo p202_setup_error_flashes($errors, ['platform', 'conversion_name', 'currency', 'from', 'to']);
}
?>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <section class="p202-panel">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Which conversions</h2>
                <span class="p202-panel__sub">nothing is changed here</span>
            </div>
            <div class="p202-panel__body">
                <form method="get" action="<?php echo p202_setup_e($self); ?>" id="offline_export_form">
                    <input type="hidden" name="check" value="1">
                    <div class="mb-3">
                        <label class="form-label" for="platform">Ad platform</label>
                        <select id="platform" name="platform"
                            class="form-select<?php echo p202_setup_invalid($errors, 'platform'); ?>">
                            <?php echo p202_setup_options($platforms, $form['platform']); ?>
                        </select>
                        <?php echo p202_setup_feedback($errors, 'platform'); ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="conversion_name">Conversion name</label>
                        <input type="text" id="conversion_name" name="conversion_name" maxlength="100" required
                            class="form-control<?php echo p202_setup_invalid($errors, 'conversion_name'); ?>"
                            value="<?php echo p202_setup_e($form['conversion_name']); ?>">
                        <div class="form-text">
                            The conversion action you import into, spelled exactly as in the ad platform
                            (Google Ads: Goals › Conversions).
                        </div>
                        <?php echo p202_setup_feedback($errors, 'conversion_name'); ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="from">First day</label>
                            <input type="date" id="from" name="from" required
                                class="form-control<?php echo p202_setup_invalid($errors, 'from'); ?>"
                                value="<?php echo p202_setup_e($form['from']); ?>">
                            <?php echo p202_setup_feedback($errors, 'from'); ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="to">Last day</label>
                            <input type="date" id="to" name="to" required
                                class="form-control<?php echo p202_setup_invalid($errors, 'to'); ?>"
                                value="<?php echo p202_setup_e($form['to']); ?>">
                            <?php echo p202_setup_feedback($errors, 'to'); ?>
                        </div>
                        <div class="col-12 form-text mt-1">
                            The days the conversions happened, in your account's time zone. The form starts on the
                            last 30 days; both platforms keep a click id for 90.
                        </div>
                    </div>
                    <details class="p202-disclosure mb-3"<?php echo isset($errors['currency']) ? ' open' : ''; ?>>
                        <summary>Advanced <span class="p202-disclosure__hint">currency</span></summary>
                        <div class="p202-disclosure__body">
                            <label class="form-label" for="currency">Currency</label>
                            <input type="text" id="currency" name="currency" maxlength="3" pattern="[A-Za-z]{3}"
                                class="form-control<?php echo p202_setup_invalid($errors, 'currency'); ?>"
                                value="<?php echo p202_setup_e($form['currency']); ?>">
                            <div class="form-text">
                                Your account records amounts in <?php echo p202_setup_e($accountCurrency); ?>.
                            </div>
                            <?php echo p202_setup_feedback($errors, 'currency'); ?>
                        </div>
                    </details>
                    <div class="p202-form-actions">
                        <?php $checkClass = ($result['rows'] ?? 0) > 0 ? 'btn-secondary' : 'btn-primary'; ?>
                        <button type="submit"<?php echo $mayExport ? '' : ' disabled'; ?>
                            class="btn <?php echo $checkClass; ?>">
                            Check these conversions
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
    <div class="col-12 col-lg-5">
        <section class="p202-panel" id="offline-export-result">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">The file</h2>
                <?php if ($result !== null) { ?>
                    <span class="p202-pill p202-pill--accent"><?php echo $count($result['rows']); ?></span>
                <?php } ?>
            </div>
            <div class="p202-panel__body">
                <?php if ($result === null) { ?>
                    <p class="text-secondary mb-0">
                        Choose the platform, the conversion name and the days, then
                        <strong>Check these conversions</strong>. This panel then says how many go in the file
                        and offers it.
                    </p>
                <?php } elseif ($result['rows'] === 0) { ?>
                    <div class="p202-empty">
                        <i class="bi bi-inbox p202-empty__icon"></i>
                        <strong class="p202-empty__title">No conversions to export</strong>
                        <div>
                            None of these days' conversions came from a click with a <?php echo $clickId; ?>.
                            Widen the days, or check that your ad links carry it (auto-tagging).
                        </div>
                    </div>
                <?php } else { ?>
                    <p class="mb-3">
                        Every conversion that counts, on a click from
                        <?php echo p202_setup_e($platforms[$form['platform']]); ?>, at its value net of refunds in
                        <?php echo p202_setup_e($form['currency']); ?>, with times in UTC. Upload it in the ad
                        platform's offline conversion import.
                        <?php if ($form['platform'] === 'google') { ?>
                            Each row carries its order id, so uploading an overlapping window again adds nothing.
                        <?php } ?>
                    </p>
                    <div class="p202-form-actions">
                        <a class="btn btn-primary" id="offline-export-download"
                            href="<?php echo p202_setup_e($download); ?>">
                            Download <?php echo $count($result['rows']); ?>
                        </a>
                    </div>
                <?php } ?>
                <?php if ($leftOut !== []) { ?>
                    <p class="text-secondary small mt-3 mb-0">
                        Left out: <?php echo p202_setup_e(implode('; ', $leftOut)); ?>.
                    </p>
                <?php } ?>
                <?php if ($result !== null && $result['truncated']) {
                    echo p202_flash('warn', 'The file stops at 100,000 conversions, the most one upload takes.'
                        . ' Export the rest from ' . date('M j, Y', (int) $result['next_time_from']) . '.');
                } ?>
            </div>
        </section>
    </div>
</div>

<?php template_bottom();
