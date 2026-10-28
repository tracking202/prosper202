<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

require_once __DIR__ . '/_includes/setup_ui.php';

use Prosper202\OfflineConversion\Consent;
use Prosper202\OfflineConversion\ConversionNames;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\LeftOut;
use Prosper202\OfflineConversion\OfflineExport;
use Prosper202\OfflineConversion\OfflineFile;

/*
 * Setup › Offline Conversions: the account's conversions as Google Ads' and
 * Microsoft Advertising's offline conversion import files
 * (Prosper202\OfflineConversion), so the platforms' bidding learns which
 * clicks converted.
 *
 * - Download: a file now, and what it leaves out and why (GET, so a choice
 *   is a link). The same file as GET /api/v3/conversions/offline-export.
 * - Conversion names: the conversion action (Google) or goal (Microsoft)
 *   each campaign's conversions are sent as, and the account's name for the
 *   rest.
 * - Fetch URLs: a URL a platform fetches the file from on a schedule. Its
 *   key is shown once, when it is made or replaced (kept in the session for
 *   the one page that shows it), and never again: only its hash is stored.
 *
 * A role without campaign data reads no conversion value anywhere, and every
 * file carries values, so such a role gets the names and nothing else.
 */

$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);
$base = get_absolute_url();
$self = $base . 'tracking202/setup/offline_conversions.php';
$canExport = $userObj->hasPermission('access_to_campaign_data');
$now = time();
$feedStore = new FeedStore($conn);
$names = new ConversionNames($conn);
$errors = [];
$tokenRefused = false;

// The download form's choices, read as the API reads its query.
$downloadKeys = ['format', 'days', 'conversion_name', 'ad_user_data', 'ad_personalization'];
$downloadParams = array_intersect_key($_GET, array_flip($downloadKeys));
$downloadParams['format'] ??= Format::GOOGLE->value;
[$downloadOptions, $downloadErrors] = ExportOptions::fromRequest($downloadParams, $now);

if (isset($_GET['download']) && $canExport && $downloadOptions !== null) {
    try {
        [$handle, $summary] = OfflineFile::write($conn, $userId, $downloadOptions);
        OfflineFile::send($handle, OfflineFile::filename($downloadOptions));
        exit;
    } catch (\Throwable $e) {
        error_log(p202UncaughtLogLine('Setup › Offline Conversions: the download failed', $e));
        $errors['download'] = 'The file could not be written. Try again; if it keeps failing, the server\'s error log says why.';
    }
}

// The account's campaigns: the only ones a name is taken for.
$campaigns = [];
foreach (p202_setup_rows($db, "SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns WHERE user_id = '" . $userId . "' AND aff_campaign_deleted = 0 ORDER BY aff_campaign_name ASC, aff_campaign_id ASC") as $row) {
    $campaigns[(int) $row['aff_campaign_id']] = (string) $row['aff_campaign_name'];
}

$posted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $posted = $_POST;
    if (!AUTH::check_csrf_token()) {
        $tokenRefused = true;
    } elseif ($action === 'save_names') {
        // Every name on the form, each one checked; a campaign that is not
        // the account's is refused rather than skipped.
        $submitted = is_array($_POST['names'] ?? null) ? $_POST['names'] : [];
        $save = [];
        foreach (ConversionNames::PLATFORMS as $platform) {
            $byCampaign = is_array($submitted[$platform] ?? null) ? $submitted[$platform] : [];
            foreach ($byCampaign as $campaignKey => $value) {
                $campaignId = preg_match('/\A(0|[1-9][0-9]{0,8})\z/D', (string) $campaignKey) === 1 ? (int) $campaignKey : -1;
                $field = 'names_' . $platform . '_' . $campaignKey;
                if ($campaignId < 0 || ($campaignId > 0 && !isset($campaigns[$campaignId]))) {
                    $errors['names'] = 'The form named a campaign that is not one of yours; nothing was saved. Reload the page.';
                    continue;
                }
                if (!is_string($value)) {
                    $errors[$field] = 'One name per field.';
                    continue;
                }
                $problem = $value === '' ? null : ConversionNames::problem($value);
                if ($problem !== null) {
                    $errors[$field] = $problem;
                    continue;
                }
                $save[$platform][$campaignId] = $value;
            }
        }
        if ($errors === []) {
            $names->save($userId, $save, $now);
            header('location: ' . $self . '?done=names#names');
            die();
        }
    } elseif (!$canExport) {
        $errors['permission'] = 'Your role does not include campaign data, which every file carries, so it cannot make fetch URLs.';
    } elseif ($action === 'create_feed') {
        [$settings, $settingErrors] = FeedStore::settings($_POST);
        $errors += $settingErrors;
        if ($settings !== null) {
            try {
                [$feed, $key] = $feedStore->create($userId, $settings, $now);
                $_SESSION['p202_offline_feed_url'] = ['feed_id' => $feed->feedId, 'url' => FeedStore::url(p202TrackingBaseUrl(), $key)];
                header('location: ' . $self . '?done=feed#feeds');
                die();
            } catch (\OverflowException $e) {
                $errors['feeds'] = $e->getMessage();
            }
        }
    } elseif ($action === 'replace_key' || $action === 'delete_feed') {
        $feedId = preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) ($_POST['feed_id'] ?? '')) === 1 ? (int) $_POST['feed_id'] : 0;
        if ($action === 'replace_key') {
            $key = $feedId > 0 ? $feedStore->replaceKey($userId, $feedId, $now) : null;
            if ($key !== null) {
                $_SESSION['p202_offline_feed_url'] = ['feed_id' => $feedId, 'url' => FeedStore::url(p202TrackingBaseUrl(), $key)];
                header('location: ' . $self . '?done=key#feeds');
                die();
            }
        } elseif ($feedId > 0 && $feedStore->delete($userId, $feedId)) {
            header('location: ' . $self . '?done=deleted#feeds');
            die();
        }
        $errors['feeds'] = 'That fetch URL is not one of yours, or was deleted.';
    } else {
        $errors['action'] = 'The form did not say what to do; nothing was changed.';
    }
}

// A URL made or replaced a moment ago: shown on this page once.
$newUrl = null;
if (isset($_SESSION['p202_offline_feed_url']) && is_array($_SESSION['p202_offline_feed_url'])) {
    $newUrl = $_SESSION['p202_offline_feed_url'];
    unset($_SESSION['p202_offline_feed_url']);
}

$stored = $names->read($userId);
$feeds = $canExport ? $feedStore->list($userId) : [];
$summary = null;
if ($canExport && $downloadOptions !== null) {
    try {
        $summary = (new OfflineExport($conn))->write($userId, $downloadOptions, null);
    } catch (\Throwable $e) {
        error_log(p202UncaughtLogLine('Setup › Offline Conversions: the count failed', $e));
        $errors['summary'] = 'The conversions could not be counted. Reload the page; if it keeps failing, the server\'s error log says why.';
    }
}
$feedBase = p202TrackingBaseUrl();
$httpsBase = str_starts_with($feedBase, 'https://');

// What a name field shows: what was posted when the save was refused, else what is stored.
$nameValue = static function (string $platform, int $campaignId) use ($posted, $stored): string {
    $submitted = $posted['names'][$platform][$campaignId] ?? null;
    if (is_string($submitted)) {
        return $submitted;
    }
    if ($campaignId === 0) {
        return (string) ($stored[$platform]['default'] ?? '');
    }

    return (string) ($stored[$platform]['campaigns'][$campaignId] ?? '');
};
$feedValue = static fn (string $key, string $default = ''): string => is_string($posted[$key] ?? null) && ($posted['action'] ?? '') === 'create_feed' ? (string) $posted[$key] : $default;
$consentLabel = static fn (?Consent $c): string => $c === null ? 'not stated' : $c->cell();
$when = static fn (int $ts): string => date('Y-m-d H:i', $ts);
$formatChoices = [];
foreach (Format::cases() as $format) {
    $formatChoices[$format->value] = $format->label();
}
$consentChoices = ['' => 'Not stated (empty)', Consent::GRANTED->value => 'Granted', Consent::DENIED->value => 'Denied'];

AUTH::set_timezone($_SESSION['user_timezone'] ?? date_default_timezone_get());
template_top('Offline Conversions');
?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-cloud-upload"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Offline Conversions</h1>
        <p class="p202-page-header__desc">Send your conversions back to Google Ads and Microsoft Advertising as their offline conversion import file, so their bidding learns which clicks converted.</p>
    </div>
</div>

<?php
$done = is_string($_GET['done'] ?? null) ? $_GET['done'] : '';
$doneText = [
    'names' => 'Conversion names saved.',
    'deleted' => 'Fetch URL deleted. It answers nothing from now on.',
][$done] ?? null;
if ($doneText !== null) {
    echo p202_flash('ok', $doneText);
}
if ($tokenRefused) {
    echo p202_flash('bad', 'Your session\'s security token did not match, so nothing was changed. Reload the page and try again.');
}
echo p202_setup_error_flashes($errors, array_values(array_filter(array_keys($errors), static fn (string $k): bool => str_starts_with($k, 'names_') || in_array($k, ['format', 'days', 'conversion_name', 'ad_user_data', 'ad_personalization'], true))));
if ($newUrl !== null) { ?>
    <div class="alert alert-success p202-flash" role="status"><i class="bi bi-check-circle"></i><div class="p202-flash__body">
        <strong><?php echo $done === 'key' ? 'New URL made. The old one answers nothing from now on.' : 'Fetch URL made.'; ?></strong>
        Copy it now: it is shown this once, and Prosper202 keeps only a fingerprint of it. A lost URL is replaced, not shown again.
        <?php echo p202_setup_code_box((string) $newUrl['url'], ['id' => 'new-feed-url']); ?>
    </div></div>
<?php }
if (!$canExport) {
    echo p202_flash('warn', 'Your role does not include campaign data. Every file carries each conversion\'s value, so downloads and fetch URLs are not offered to it. You can still set conversion names.');
}
?>

<div class="row g-4">
    <?php if ($canExport) { ?>
    <div class="col-12 col-lg-5">
        <section class="p202-panel" id="download">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Download a file</h2>
                <span class="p202-panel__sub">upload it once by hand</span>
            </div>
            <div class="p202-panel__body">
                <form method="get" action="<?php echo p202_setup_e($self); ?>#download">
                    <div class="mb-3">
                        <label class="form-label" for="format">For</label>
                        <select class="form-select<?php echo p202_setup_invalid($downloadErrors, 'format'); ?>" id="format" name="format">
                            <?php echo p202_setup_options($formatChoices, (string) $downloadParams['format']); ?>
                        </select>
                        <?php echo p202_setup_feedback($downloadErrors, 'format'); ?>
                        <div class="form-text">Google's Data Manager file carries gclid, gbraid and wbraid. The Uploads file is Google's classic template, which has a column for the gclid only.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="days">Conversions from the last</label>
                        <div class="input-group">
                            <input type="number" class="form-control<?php echo p202_setup_invalid($downloadErrors, 'days'); ?>" id="days" name="days" min="1" max="<?php echo ExportOptions::MAX_DAYS; ?>" value="<?php echo p202_setup_e((string) ($downloadParams['days'] ?? ExportOptions::MAX_DAYS)); ?>">
                            <span class="input-group-text">days</span>
                        </div>
                        <?php echo p202_setup_feedback($downloadErrors, 'days'); ?>
                        <div class="form-text">90 by default: neither platform imports a conversion more than 90 days after its click.</div>
                    </div>
                    <details class="p202-disclosure mb-3" data-p202-remember="setup-offline-download-advanced"<?php echo isset($downloadErrors['conversion_name']) || isset($downloadErrors['ad_user_data']) || isset($downloadErrors['ad_personalization']) ? ' open' : ''; ?>>
                        <summary>Advanced <span class="p202-disclosure__hint">consent columns, one conversion name for every row</span></summary>
                        <div class="p202-disclosure__body">
                            <?php foreach (['ad_user_data' => 'Ad User Data', 'ad_personalization' => 'Ad Personalization'] as $key => $label) { ?>
                            <div class="mb-3">
                                <label class="form-label" for="dl_<?php echo $key; ?>"><?php echo p202_setup_e($label); ?> (Google)</label>
                                <select class="form-select<?php echo p202_setup_invalid($downloadErrors, $key); ?>" id="dl_<?php echo $key; ?>" name="<?php echo $key; ?>">
                                    <?php echo p202_setup_options($consentChoices, (string) ($downloadParams[$key] ?? '')); ?>
                                </select>
                                <?php echo p202_setup_feedback($downloadErrors, $key); ?>
                            </div>
                            <?php } ?>
                            <div class="form-text mb-3">Google asks for these with traffic from the European Economic Area. Prosper202 does not record a visitor's consent, so state what your consent banner collected, for every row; leave them empty when you cannot.</div>
                            <label class="form-label" for="dl_conversion_name">Send every conversion as</label>
                            <input type="text" class="form-control<?php echo p202_setup_invalid($downloadErrors, 'conversion_name'); ?>" id="dl_conversion_name" name="conversion_name" maxlength="<?php echo ConversionNames::MAX_LENGTH; ?>" value="<?php echo p202_setup_e((string) ($downloadParams['conversion_name'] ?? '')); ?>">
                            <?php echo p202_setup_feedback($downloadErrors, 'conversion_name'); ?>
                            <div class="form-text">Empty uses each campaign's conversion name below.</div>
                        </div>
                    </details>
                    <div class="p202-form-actions">
                        <button type="submit" class="btn btn-secondary">Count again</button>
                        <button type="submit" class="btn btn-primary" name="download" value="1"><i class="bi bi-download"></i> Download</button>
                    </div>
                </form>
            </div>
            <?php if ($summary !== null) {
                $leftOut = array_filter($summary->leftOut, static fn (int $n): bool => $n > 0);
                ?>
            <div class="p202-panel__body">
                <p class="mb-2"><strong><?php echo number_format($summary->rows); ?></strong> of <?php echo number_format($summary->conversions); ?> conversions in the window go in this file<?php echo $summary->conversions > 0 ? ', in ' . p202_setup_e($summary->currency) . ', times in ' . p202_setup_e($summary->zone) : ''; ?>.</p>
                <?php if ($leftOut !== []) { ?>
                <div class="p202-table-wrap">
                    <table class="table p202-table">
                        <thead><tr><th>Left out</th><th class="num">Conversions</th></tr></thead>
                        <tbody>
                            <?php foreach ($leftOut as $reason => $count) { ?>
                            <tr><td><?php echo p202_setup_e(LeftOut::from($reason)->explanation()); ?></td><td class="num"><?php echo number_format($count); ?></td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
                <div class="form-text">Only conversions whose click was named, by the postback, the pixel, the API or a tracking cookie, are sent: a click guessed from an IP address would teach the platform's bidding the wrong clicks.</div>
            </div>
            <?php } ?>
        </section>
    </div>
    <?php } ?>

    <div class="col-12<?php echo $canExport ? ' col-lg-7' : ''; ?>">
        <form class="p202-panel" id="names" method="post" action="<?php echo p202_setup_e($self); ?>#names">
            <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
            <input type="hidden" name="action" value="save_names">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Conversion names</h2>
                <span class="p202-panel__sub">exactly as the platform spells them</span>
            </div>
            <div class="p202-panel__body">
                <p>Each row of a file names the conversion action (Google Ads) or offline conversion goal (Microsoft Advertising) it counts toward, spelled and capitalised exactly as you created it there. A campaign without a name of its own is sent under the account's name; with neither, its conversions are left out.</p>
                <div class="p202-table-wrap">
                    <table class="table p202-table">
                        <thead><tr><th>Campaign</th><th>Google Ads conversion action</th><th>Microsoft Advertising goal</th></tr></thead>
                        <tbody>
                            <?php
                            $rows = [0 => 'Every other campaign'] + $campaigns;
                            foreach ($rows as $campaignId => $campaignName) { ?>
                            <tr>
                                <td><?php echo $campaignId === 0 ? '<strong>' . p202_setup_e($campaignName) . '</strong>' : p202_setup_e($campaignName); ?></td>
                                <?php foreach (ConversionNames::PLATFORMS as $platform) {
                                    $field = 'names_' . $platform . '_' . $campaignId;
                                    $label = $platform === ConversionNames::GOOGLE ? 'Google Ads conversion action' : 'Microsoft Advertising goal'; ?>
                                <td>
                                    <input type="text" class="form-control form-control-sm<?php echo p202_setup_invalid($errors, $field); ?>" name="names[<?php echo $platform; ?>][<?php echo $campaignId; ?>]" maxlength="<?php echo ConversionNames::MAX_LENGTH; ?>" value="<?php echo p202_setup_e($nameValue($platform, $campaignId)); ?>" aria-label="<?php echo p202_setup_e($label . ' for ' . $campaignName); ?>">
                                    <?php echo p202_setup_feedback($errors, $field); ?>
                                </td>
                                <?php } ?>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Save names</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($canExport) { ?>
    <div class="col-12">
        <section class="p202-panel" id="feeds">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Fetch URLs</h2>
                <span class="p202-pill"><?php echo count($feeds) . (count($feeds) === 1 ? ' URL' : ' URLs'); ?></span>
                <span class="p202-panel__sub">the platform fetches the file on its schedule, so the upload runs itself</span>
            </div>
            <div class="p202-panel__body">
                <?php if (!$httpsBase) {
                    echo p202_flash('warn', 'Fetch URLs are made on this install\'s tracking domain, ' . $feedBase . ', which is not https://. Google Ads fetches only https:// URLs: set an https:// tracking domain in the install owner\'s Personal settings. Microsoft Advertising takes http:// too.');
                } ?>
                <p class="mb-2"><strong>Google Ads:</strong> Goals › Conversions › Uploads, then Schedules, a new schedule from HTTPS; or Data Manager's HTTPS source for the Data Manager file. <strong>Microsoft Advertising:</strong> Conversions › Offline conversions › Schedules › Schedule, HTTPS. Paste the URL; both ask for a user name and password, which Prosper202 does not read (the key is in the URL), so enter anything. Schedule a daily fetch.</p>
                <?php if ($feeds === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-link-45deg p202-empty__icon"></i>
                    <strong class="p202-empty__title">No fetch URLs yet</strong>
                    <div>Make one below for each platform, or each conversion action if the platform takes one file per action.</div>
                </div>
                <?php } else { ?>
                <ul class="p202-list">
                    <?php foreach ($feeds as $feed) { ?>
                    <li class="p202-list__item">
                        <span class="p202-list__name"><?php echo p202_setup_e($feed->format->label()); ?></span>
                        <span class="p202-pill">last <?php echo (int) $feed->days; ?> days</span>
                        <?php if ($feed->conversionName !== null) {
                            ?><span class="p202-pill p202-pill--accent">only <?php echo p202_setup_e($feed->conversionName); ?></span><?php
                        } ?>
                        <?php if ($feed->format->takesConsent()) {
                            ?><span class="p202-pill">Ad User Data: <?php echo p202_setup_e($consentLabel($feed->adUserData)); ?> · Ad Personalization: <?php echo p202_setup_e($consentLabel($feed->adPersonalization)); ?></span><?php
                        } ?>
                        <span class="p202-list__actions">
                            <form method="post" action="<?php echo p202_setup_e($self); ?>#feeds" class="d-inline" data-p202-confirm="Make a new URL for this file? The current URL stops answering at once, so the platform's schedule needs the new one.">
                                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                                <input type="hidden" name="action" value="replace_key"><input type="hidden" name="feed_id" value="<?php echo (int) $feed->feedId; ?>">
                                <button type="submit" class="p202-list__action">replace URL</button>
                            </form>
                            <form method="post" action="<?php echo p202_setup_e($self); ?>#feeds" class="d-inline" data-p202-confirm="Delete this fetch URL? It stops answering at once.">
                                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                                <input type="hidden" name="action" value="delete_feed"><input type="hidden" name="feed_id" value="<?php echo (int) $feed->feedId; ?>">
                                <button type="submit" class="p202-list__action p202-list__action--danger">delete</button>
                            </form>
                        </span>
                        <span class="p202-list__meta">made <?php echo p202_setup_e($when($feed->createdAt)); ?><?php echo $feed->keyCreatedAt !== $feed->createdAt ? ', URL replaced ' . p202_setup_e($when($feed->keyCreatedAt)) : ''; ?> · <?php echo $feed->lastFetchedAt === null ? 'not fetched yet' : 'last fetched ' . p202_setup_e($when($feed->lastFetchedAt)) . ', ' . number_format((int) $feed->lastFetchRows) . ' rows'; ?></span>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
            </div>
            <form class="p202-panel__body" method="post" action="<?php echo p202_setup_e($self); ?>#feeds">
                <?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
                <input type="hidden" name="action" value="create_feed">
                <h3 class="p202-panel__title mb-3">Make a fetch URL</h3>
                <div class="row g-3 mb-3">
                    <div class="col-sm-7">
                        <label class="form-label" for="feed_format">For</label>
                        <select class="form-select<?php echo p202_setup_invalid($errors, 'format'); ?>" id="feed_format" name="format">
                            <?php echo p202_setup_options($formatChoices, $feedValue('format', Format::GOOGLE->value)); ?>
                        </select>
                        <?php echo p202_setup_feedback($errors, 'format'); ?>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label" for="feed_days">Conversions from the last</label>
                        <div class="input-group">
                            <input type="number" class="form-control<?php echo p202_setup_invalid($errors, 'days'); ?>" id="feed_days" name="days" min="<?php echo FeedStore::MIN_DAYS; ?>" max="<?php echo ExportOptions::MAX_DAYS; ?>" value="<?php echo p202_setup_e($feedValue('days', (string) FeedStore::DEFAULT_DAYS)); ?>">
                            <span class="input-group-text">days</span>
                        </div>
                        <?php echo p202_setup_feedback($errors, 'days'); ?>
                    </div>
                </div>
                <div class="form-text mb-3">Every fetch sends the window again, and the platforms count a conversion they already have once, so a window longer than the time between fetches loses nothing when one fetch fails. A conversion whose click is under 6 hours old waits for the next fetch.</div>
                <details class="p202-disclosure mb-3" data-p202-remember="setup-offline-feed-advanced"<?php echo isset($errors['conversion_name']) || isset($errors['ad_user_data']) || isset($errors['ad_personalization']) ? ' open' : ''; ?>>
                    <summary>Advanced <span class="p202-disclosure__hint">one conversion action per URL, consent columns</span></summary>
                    <div class="p202-disclosure__body">
                        <div class="mb-3">
                            <label class="form-label" for="feed_conversion_name">Only conversions named</label>
                            <input type="text" class="form-control<?php echo p202_setup_invalid($errors, 'conversion_name'); ?>" id="feed_conversion_name" name="conversion_name" maxlength="<?php echo ConversionNames::MAX_LENGTH; ?>" value="<?php echo p202_setup_e($feedValue('conversion_name')); ?>">
                            <?php echo p202_setup_feedback($errors, 'conversion_name'); ?>
                            <div class="form-text">For a platform source that takes one file per conversion action: one URL per name. Empty sends every name.</div>
                        </div>
                        <?php foreach (['ad_user_data' => 'Ad User Data', 'ad_personalization' => 'Ad Personalization'] as $key => $label) { ?>
                        <div class="mb-3">
                            <label class="form-label" for="feed_<?php echo $key; ?>"><?php echo p202_setup_e($label); ?> (Google)</label>
                            <select class="form-select<?php echo p202_setup_invalid($errors, $key); ?>" id="feed_<?php echo $key; ?>" name="<?php echo $key; ?>">
                                <?php echo p202_setup_options($consentChoices, $feedValue($key)); ?>
                            </select>
                            <?php echo p202_setup_feedback($errors, $key); ?>
                        </div>
                        <?php } ?>
                        <div class="form-text">The consent columns are Google's; a Microsoft URL leaves them out.</div>
                    </div>
                </details>
                <div class="p202-form-actions">
                    <button type="submit" class="btn btn-primary">Make URL</button>
                </div>
            </form>
        </section>
    </div>
    <?php } ?>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
