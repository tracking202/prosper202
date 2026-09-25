<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps (plan §5.6, PR 11).
 *
 * On the v2 shell, so no Bootstrap 3 or Flat UI class appears here or in the
 * partials under mobile_apps/ — tests/Api/V3/NoLegacyBootstrapClassesTest.php
 * scans them and fails the build if one does.
 *
 * The filters are one GET form, so the report a person is looking at is the
 * URL they can send to someone else.
 *
 * @var array<string, mixed> $mobileReport  built by MobileAppsReportController
 */

$C = \Tracking202\Analyze\MobileAppsReportController::class;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn (mixed $v): string => number_format((float)$v);
$pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1) . '%';
$currency = (string)$mobileReport['currency'];
$money = static fn (mixed $v): string => (string)dollar_format((float)$v, $currency);

/** Registration to name, key and platform, for the rows that only carry the registration. */
$appNames = [];
foreach ($mobileReport['apps'] as $knownApp) {
    $appNames[(string)$knownApp['registration_id']] = [
        'name' => (string)($knownApp['app_name'] ?? ''),
        'key' => (string)($knownApp['app_key'] ?? ''),
        'platform' => (string)($knownApp['platform'] ?? ''),
    ];
}

$setupUrl = rtrim((string)$mobileReport['self'], '/');
$setupUrl = substr($setupUrl, 0, strrpos($setupUrl, '/tracking202/')) . '/tracking202/setup/mobile_apps.php';

/**
 * An empty state, with its parts — the container alone carries none of the
 * component's typography (error pattern #19) and the standard asks for one
 * click rather than a paragraph of directions.
 */
$empty = static function (
    string $icon,
    string $title,
    string $body,
    string $action = '',
    string $href = ''
) use ($e): string {
    return '<div class="p202-empty">'
        . '<i class="bi ' . $e($icon) . ' p202-empty__icon"></i>'
        . '<strong class="p202-empty__title">' . $e($title) . '</strong>'
        . '<div>' . $body . '</div>'
        . ($action === '' ? '' : '<div class="p202-empty__action">'
            . '<a class="btn btn-primary btn-sm" href="' . $e($href) . '">' . $e($action) . '</a></div>')
        . '</div>';
};

$self = (string)$mobileReport['self'];
$view = (string)$mobileReport['view'];
$filters = $mobileReport['filters'];
$apps = $mobileReport['apps'];
$platform = (string)$filters['platform'];

$customRange = (string)$mobileReport['customRange'];
$isCustom = $filters['range'] === $customRange;

/**
 * A link to this page with some filters changed and the rest kept.
 *
 * The dates ride along only for a custom window, matching the rule the
 * controller reads by: a link that carried both a preset and a pair of dates
 * would say two different things about which window it means. A platform
 * change drops a grouping and a trust filter the new platform does not
 * have, rather than carrying them into a report that would refuse them.
 */
$link = static function (array $changes) use ($self, $filters, $view, $customRange, $C): string {
    // array_key_exists, not ??: a key given as null means "drop this one",
    // which `?? $filters[...]` would silently read as "keep it".
    $pick = static fn (string $key, mixed $current): mixed
        => array_key_exists($key, $changes) ? $changes[$key] : $current;

    $range = (string)$pick('range', $filters['range']);
    $custom = $range === $customRange;
    $platform = (string)$pick('platform', $filters['platform']);
    $groupBy = (string)$pick('group_by', $filters['group_by']);
    if (!isset($C::groupingsFor($platform)[$groupBy])) {
        $groupBy = null;
    }
    $query = array_filter([
        'view' => $pick('view', $view),
        'platform' => $platform,
        'group_by' => $groupBy,
        'range' => $range,
        'from' => $custom ? $pick('from', $filters['from']) : null,
        'to' => $custom ? $pick('to', $filters['to']) : null,
        'registration_id' => $pick('registration_id', $filters['registration_id']),
        'signature' => $platform === 'ios' ? $pick('signature', $filters['signature']) : null,
        'trusted' => $platform === 'android' ? $pick('trusted', $filters['trusted']) : null,
        'status' => $pick('status', $filters['status']),
        'page' => $changes['page'] ?? null,
        'download' => $changes['download'] ?? null,
    ], static fn ($v): bool => $v !== null && $v !== '');

    return $self . '?' . http_build_query($query);
};

/** The pill tone for a signature state, from the same vocabulary as Setup. */
$signatureTone = static fn (mixed $state): string => [
    'valid' => 'p202-pill p202-pill--good',
    'invalid' => 'p202-pill p202-pill--bad',
    'development' => 'p202-pill p202-pill--warn',
][(string)$state] ?? 'p202-pill';

/** A value the report did not receive, said rather than left blank. */
$notGiven = '<span class="text-secondary">not given</span>';

/**
 * What the first column holds, per grouping.
 *
 * The pieces come from the controller, which the CSV reads too, so the two
 * columns cannot say different things about the same group. Only the
 * decoration is local: an app's id and a source's campaign are muted here
 * and plain in the file.
 */
$groupCell = static function (array $group, string $groupBy) use ($e, $notGiven, $C): string {
    $parts = $C::groupLabelParts($group, $groupBy);
    if ($parts === []) {
        return $groupBy === 'campaign' ? '<span class="text-secondary">no campaign</span>' : $notGiven;
    }
    $first = $e(array_shift($parts));

    return $parts === []
        ? $first
        : $first . ' <span class="text-secondary">' . $e(implode(' · ', $parts)) . '</span>';
};

$tabs = ['report' => 'Report', 'funnel' => 'Funnel', 'postbacks' => 'iOS postbacks', 'notifications' => 'Postbacks sent', 'verify' => 'Verify'];
// The funnel and the outbox are an Android install's: an account with no
// Android app is not offered two tabs that can only say so. Their URLs
// still answer (a link someone kept, the view it names), explaining why.
$hasAndroid = false;
foreach ((array)($mobileReport['apps'] ?? []) as $appRow) {
    $hasAndroid = $hasAndroid || strtolower((string)($appRow['platform'] ?? '')) === 'android';
}
if (!$hasAndroid) {
    foreach (['funnel', 'notifications'] as $androidOnly) {
        if ($view !== $androidOnly) {
            unset($tabs[$androidOnly]);
        }
    }
}

template_top('Analyze Mobile Apps', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
    <div class="p202-page-header__icon"><i class="bi bi-phone"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Mobile App Attribution</h1>
        <p class="p202-page-header__desc">What the apps' installs add up to on iOS and Android — and the goals they reached, the funnel, the postbacks behind the totals, and whether each traffic source was told.</p>
    </div>
</div>

<?php foreach ($mobileReport['flashes'] as $flash) {
    echo p202_flash($flash['kind'], $flash['text']);
} ?>

<nav class="nav p202-tabs" aria-label="Mobile app views">
    <?php foreach ($tabs as $key => $label) { ?>
        <a class="nav-link<?php echo $view === $key ? ' active' : ''; ?>"
           <?php echo $view === $key ? 'aria-current="page" ' : ''; ?>
           href="<?php echo $e($link(['view' => $key, 'page' => null])); ?>"><?php echo $e($label); ?></a>
    <?php } ?>
</nav>

<?php if ($view === 'verify') {
    require __DIR__ . '/mobile_apps/_verify.php';
} else { ?>
    <!-- ── Filters, shared by every view but Verify ─────────────────── -->
    <form class="p202-table-toolbar" method="get" action="<?php echo $e($self); ?>">
        <input type="hidden" name="view" value="<?php echo $e($view); ?>">
        <?php /* Carried on every view so applying a filter from another tab
                 does not quietly reset the Report tab's grouping. `page`
                 deliberately is not: a new filter starts at the first page. */ ?>
        <input type="hidden" name="group_by" value="<?php echo $e($filters['group_by']); ?>">
        <div class="p202-toolbar">
            <?php if ($view === 'report') { ?>
                <label class="form-label mb-0" for="platform">Platform</label>
                <select class="form-select form-select-sm w-auto" id="platform" name="platform">
                    <?php foreach ($mobileReport['platforms'] as $key => $label) { ?>
                        <option value="<?php echo $e($key); ?>"<?php echo $platform === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                    <?php } ?>
                </select>
            <?php } else { ?>
                <input type="hidden" name="platform" value="<?php echo $e($platform); ?>">
            <?php } ?>

            <label class="form-label mb-0" for="range">Range</label>
            <select class="form-select form-select-sm w-auto" id="range" name="range" data-p202-range="<?php echo $e($customRange); ?>">
                <?php foreach ($mobileReport['ranges'] as $key => $label) { ?>
                    <option value="<?php echo $e($key); ?>"<?php echo $filters['range'] === (string)$key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                <?php } ?>
                <option value="<?php echo $e($customRange); ?>"<?php echo $isCustom ? ' selected' : ''; ?>>Custom Date</option>
            </select>

            <?php /* Disabled off a custom window, so a browser with no
                     JavaScript does not submit the window it is only
                     displaying. As soon as p202-ui.js runs it drops the
                     `disabled` and withholds the fields from the request by
                     removing their `name` instead — same effect on what is
                     submitted, but the fields can be typed in, which is what
                     lets typing a date select Custom Date. */ ?>
            <label class="form-label mb-0" for="from">From</label>
            <input class="form-control form-control-sm w-auto" type="date"
                   id="from" name="from" value="<?php echo $e($filters['from']); ?>"
                   data-p202-range-field="from"<?php echo $isCustom ? '' : ' disabled'; ?>>
            <label class="form-label mb-0" for="to">To</label>
            <input class="form-control form-control-sm w-auto" type="date"
                   id="to" name="to" value="<?php echo $e($filters['to']); ?>"
                   data-p202-range-field="to"<?php echo $isCustom ? '' : ' disabled'; ?>>
            <?php /* Only true without JavaScript, which is the only case
                     where the fields above are really disabled; p202-ui.js
                     hides it and lets a date be typed directly. */ ?>
            <?php if (!$isCustom) { ?>
                <span class="form-text" data-p202-range-hint>Choose <em>Custom Date</em> to set these.</span>
            <?php } ?>

            <label class="form-label mb-0" for="registration_id">App</label>
            <?php
            // A registration_id with no option of its own would leave the menu on
            // "All apps" over a report that IS still filtered — and the next
            // Apply would submit the empty value and widen it without a word.
            // It happens: an app deleted since the link was made, one past
            // RegisteredApps::MAX, a postback for an app that was never
            // registered, or an apps read that failed.
            $appIds = array_map(static fn (array $a): string => (string)($a['registration_id'] ?? ''), $apps);
            $unlisted = $filters['registration_id'] !== '' && !in_array((string)$filters['registration_id'], $appIds, true);
            ?>
            <select class="form-select form-select-sm w-auto" id="registration_id" name="registration_id">
                <option value="">All apps</option>
                <?php if ($unlisted) { ?>
                    <option value="<?php echo $e($filters['registration_id']); ?>" selected>
                        registration <?php echo $e($filters['registration_id']); ?> (not in your list)
                    </option>
                <?php } ?>
                <?php foreach ($apps as $app) { ?>
                    <option value="<?php echo (int)$app['registration_id']; ?>"<?php echo (string)$filters['registration_id'] === (string)$app['registration_id'] ? ' selected' : ''; ?>><?php echo $e(($app['app_name'] ?? '') . ' (' . ((string)($app['platform'] ?? '') === 'android' ? 'Android' : 'iOS') . ')'); ?></option>
                <?php } ?>
            </select>

            <?php if (($view === 'report' && $platform === 'ios') || $view === 'postbacks') { ?>
                <label class="form-label mb-0" for="signature">Signature</label>
                <select class="form-select form-select-sm w-auto" id="signature" name="signature">
                    <option value="">Any</option>
                    <?php /* The states the column really holds, from the enum that
                             defines them, so the menu and the controller's
                             validation cannot offer and accept different sets. */ ?>
                    <?php foreach (\Api\V3\Apps\Apple\SignatureState::values() as $key) { ?>
                        <option value="<?php echo $e($key); ?>"<?php echo $filters['signature'] === $key ? ' selected' : ''; ?>><?php echo $e(ucfirst($key)); ?></option>
                    <?php } ?>
                </select>
            <?php } elseif ($view === 'report' && $platform === 'android') { ?>
                <label class="form-label mb-0" for="trusted">Installs</label>
                <select class="form-select form-select-sm w-auto" id="trusted" name="trusted">
                    <option value="">Trusted (default)</option>
                    <?php foreach ($C::TRUST_CLASSES as $key) { ?>
                        <?php if ($key !== 'trusted') { ?>
                            <option value="<?php echo $e($key); ?>"<?php echo $filters['trusted'] === $key ? ' selected' : ''; ?>><?php echo $e(ucfirst($key) . ' only'); ?></option>
                        <?php } ?>
                    <?php } ?>
                </select>
            <?php } elseif ($view === 'notifications') { ?>
                <label class="form-label mb-0" for="status">Status</label>
                <select class="form-select form-select-sm w-auto" id="status" name="status">
                    <option value="">Any</option>
                    <?php foreach (\Api\V3\Controllers\AppNotificationsController::STATUSES as $key) { ?>
                        <option value="<?php echo $e($key); ?>"<?php echo $filters['status'] === $key ? ' selected' : ''; ?>><?php echo $e(ucfirst($key)); ?></option>
                    <?php } ?>
                </select>
            <?php } ?>

            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
        <div class="p202-table-toolbar__aside">
            <span class="text-secondary small">
                <?php /* An <a>, as the kit renders it: a <span> takes no
                         focus, so the only explanation of the UTC rule would
                         be reachable by mouse alone. */ ?>
                <a href="#" class="p202-help" role="button" data-bs-toggle="tooltip"
                   aria-label="Why these dates are UTC"
                   title="Signals are grouped into whole UTC days, so these dates are UTC — unlike the click reports, which use your account timezone. Android installs count on the day they were received, with every goal they reached."><i class="bi bi-clock"></i></a>
                UTC days
            </span>
        </div>
    </form>
<?php } ?>

<?php
if ($view === 'report') {
    require __DIR__ . '/mobile_apps/_report.php';
} elseif ($view === 'funnel') {
    require __DIR__ . '/mobile_apps/_funnel.php';
} elseif ($view === 'postbacks') {
    require __DIR__ . '/mobile_apps/_postbacks.php';
} elseif ($view === 'notifications') {
    require __DIR__ . '/mobile_apps/_notifications.php';
}
?>

<?php template_bottom(); ?>
