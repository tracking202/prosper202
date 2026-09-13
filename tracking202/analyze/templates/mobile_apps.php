<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps.
 *
 * On the v2 shell, so no Bootstrap 3 or Flat UI class appears here —
 * tests/Api/V3/NoLegacyBootstrapClassesTest.php scans every file that passes
 * 'ui' => 'v2' and fails the build if one does.
 *
 * The filters are one GET form, so the report a person is looking at is the
 * URL they can send to someone else.
 *
 * @var array<string, mixed> $mobileReport  built by MobileAppsReportController
 */

$C = \Tracking202\Analyze\MobileAppsReportController::class;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn (mixed $v): string => number_format((float)$v);
$currency = (string)$mobileReport['currency'];
$money = static fn (mixed $v): string => (string)dollar_format((float)$v, $currency);

/** App Store id to registered name, for the rows that only carry the id. */
$appNames = [];
foreach ($mobileReport['apps'] as $knownApp) {
    $appNames[(string)$knownApp['app_id']] = (string)($knownApp['app_name'] ?? '');
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

$customRange = (string)$mobileReport['customRange'];
$isCustom = $filters['range'] === $customRange;

/**
 * A link to this page with some filters changed and the rest kept.
 *
 * The dates ride along only for a custom window, matching the rule the
 * controller reads by: a link that carried both a preset and a pair of dates
 * would say two different things about which window it means.
 */
$link = static function (array $changes) use ($self, $filters, $view, $customRange): string {
    // array_key_exists, not ??: a key given as null means "drop this one",
    // which `?? $filters[...]` would silently read as "keep it".
    $pick = static fn (string $key, mixed $current): mixed
        => array_key_exists($key, $changes) ? $changes[$key] : $current;

    $range = (string)$pick('range', $filters['range']);
    $custom = $range === $customRange;
    $query = array_filter([
        'view' => $pick('view', $view),
        'group_by' => $pick('group_by', $filters['group_by']),
        'range' => $range,
        'from' => $custom ? $pick('from', $filters['from']) : null,
        'to' => $custom ? $pick('to', $filters['to']) : null,
        'app_id' => $pick('app_id', $filters['app_id']),
        'signature' => $pick('signature', $filters['signature']),
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
        return $notGiven;
    }
    $first = $e(array_shift($parts));

    return $parts === []
        ? $first
        : $first . ' <span class="text-secondary">' . $e(implode(' · ', $parts)) . '</span>';
};

template_top('Analyze Mobile Apps', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
    <div class="p202-page-header__icon"><i class="bi bi-phone"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Mobile App Attribution</h1>
        <p class="p202-page-header__desc">What Apple's SKAdNetwork and AdAttributionKit postbacks add up to, the postbacks behind the totals, and a way to check that one is genuine.</p>
    </div>
</div>

<?php foreach ($mobileReport['flashes'] as $flash) {
    echo p202_flash($flash['kind'], $flash['text']);
} ?>

<nav class="nav p202-tabs" aria-label="Mobile app views">
    <?php foreach (['report' => 'Report', 'postbacks' => 'Postbacks', 'verify' => 'Verify'] as $key => $label) { ?>
        <a class="nav-link<?php echo $view === $key ? ' active' : ''; ?>"
           <?php echo $view === $key ? 'aria-current="page" ' : ''; ?>
           href="<?php echo $e($link(['view' => $key])); ?>"><?php echo $e($label); ?></a>
    <?php } ?>
</nav>

<?php if ($view === 'verify') { ?>
    <!-- ── Verify ──────────────────────────────────────────────────── -->
    <section class="p202-panel">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Check a postback's signature</h2>
            <p class="p202-panel__sub">Paste the JSON a device sent. Nothing is stored, and nothing here changes your report.</p>
        </div>
        <div class="p202-panel__body">
            <?php /* No CSRF token, and deliberately: this POST stores nothing
                     and changes nothing — it hands a pasted string to the
                     signature verifier and prints the verdict. A forged one
                     would make the victim's browser render a page. Every POST
                     on this site that writes carries a token. */ ?>
            <form method="post" action="<?php echo $e($link(['view' => 'verify'])); ?>">
                <div class="mb-3">
                    <label class="form-label" for="payload">Postback JSON</label>
                    <textarea class="form-control font-monospace" id="payload" name="payload" rows="10"
                              placeholder='{"version":"4.0","ad-network-id":"example.skadnetwork", ...}'><?php echo $e($mobileReport['verifyPayload'] ?? ''); ?></textarea>
                    <div class="form-text">An AdAttributionKit postback is the object containing <code>jws-string</code>; a SKAdNetwork one is the object Apple POSTs.</div>
                </div>
                <div class="p202-form-actions">
                    <button class="btn btn-primary" type="submit">Check signature</button>
                </div>
            </form>

            <?php $verify = $mobileReport['verify'] ?? null; ?>
            <?php if ($verify !== null) { ?>
                <hr>
                <div class="p202-strip">
                    <div class="p202-strip__row">
                        <span class="<?php echo $e($signatureTone($verify['signature'] ?? '')); ?>"><?php echo $e((string)($verify['signature'] ?? 'unknown')); ?></span>
                        <span class="p202-strip__label">Signature</span>
                        <span class="p202-strip__value"><?php echo $e((string)($verify['protocol'] ?? '')); ?></span>
                    </div>
                    <?php if (isset($verify['key_id'])) { ?>
                        <div class="p202-strip__row">
                            <span class="p202-pill">key</span>
                            <span class="p202-strip__label">Signing key</span>
                            <span class="p202-strip__value"><code><?php echo $e((string)$verify['key_id']); ?></code></span>
                        </div>
                    <?php } ?>
                </div>

                <?php if (array_key_exists('signed_message_base64', $verify)) { ?>
                    <?php if ($verify['signed_message_base64'] !== null) { ?>
                        <h3 class="p202-panel__title mt-4">Signed message</h3>
                        <p class="text-secondary small">Base64 of the exact bytes Apple signed, for diffing against another implementation when a signature unexpectedly fails.</p>
                        <div class="p202-code">
                            <pre class="p202-code__value mb-0"><?php echo $e((string)$verify['signed_message_base64']); ?></pre>
                            <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e((string)$verify['signed_message_base64']); ?>">Copy</button>
                        </div>
                    <?php } else { ?>
                        <?php /* A verdict with no signed message is the one
                                 result that looks like a bug from outside: the
                                 answer above says the signature did not check
                                 out, and the reason is that there was nothing
                                 to check it against. Say which. */ ?>
                        <div class="alert alert-warning p202-flash mt-4" role="status">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div class="p202-flash__body">
                                The bytes Apple signs could not be rebuilt from this postback, so the signature was never really tested.
                                <?php $pastedVersion = $mobileReport['verifyVersion'] ?? null; ?>
                                <?php if ($pastedVersion === null) { ?>
                                    It carries no <code>version</code>, and the fields that are signed depend on which one it is.
                                <?php } else { ?>
                                    Either a field version <code><?php echo $e($pastedVersion); ?></code> requires is missing, or that is not a version this install can check.
                                <?php } ?>
                                Checkable versions: <?php echo $e(implode(', ', (array)($verify['verifiable_versions'] ?? []))); ?>.
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

                <?php if (($verify['signature'] ?? '') === 'unverifiable' && isset($verify['key_id'])) { ?>
                    <div class="alert alert-warning p202-flash mt-4" role="status">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div class="p202-flash__body">
                            <code><?php echo $e((string)$verify['key_id']); ?></code> is not a signing key this install knows, so the signature could not be judged either way.
                            Production keys here: <?php echo $e(implode(', ', (array)($verify['known_key_ids'] ?? []))); ?>.
                            Development keys: <?php echo $e(implode(', ', (array)($verify['development_key_ids'] ?? []))); ?>.
                        </div>
                    </div>
                <?php } ?>

                <?php if (isset($verify['payload']) && is_array($verify['payload'])) { ?>
                    <h3 class="p202-panel__title mt-4">What the postback claims</h3>
                    <?php $claims = json_encode($verify['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>
                    <div class="p202-code">
                        <pre class="p202-code__value mb-0"><?php echo $e($claims === false ? '(could not be re-encoded)' : $claims); ?></pre>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
    </section>

<?php } else { ?>
    <!-- ── Filters, shared by Report and Postbacks ─────────────────── -->
    <form class="p202-table-toolbar" method="get" action="<?php echo $e($self); ?>">
        <input type="hidden" name="view" value="<?php echo $e($view); ?>">
        <?php /* Carried on both views so applying a filter from the Postbacks
                 tab does not quietly reset the Report tab's grouping. `page`
                 deliberately is not: a new filter starts at the first page. */ ?>
        <input type="hidden" name="group_by" value="<?php echo $e($filters['group_by']); ?>">
        <div class="p202-toolbar">
            <label class="form-label mb-0" for="range">Range</label>
            <select class="form-select form-select-sm" id="range" name="range" style="width:auto" data-p202-range="<?php echo $e($customRange); ?>">
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
                     lets typing a date select Custom Date. Not readonly: a
                     readonly input cannot be edited either, so that branch
                     would be just as unreachable. */ ?>
            <label class="form-label mb-0" for="from">From</label>
            <input class="form-control form-control-sm" style="width:auto" type="date"
                   id="from" name="from" value="<?php echo $e($filters['from']); ?>"
                   data-p202-range-field="from"<?php echo $isCustom ? '' : ' disabled'; ?>>
            <label class="form-label mb-0" for="to">To</label>
            <input class="form-control form-control-sm" style="width:auto" type="date"
                   id="to" name="to" value="<?php echo $e($filters['to']); ?>"
                   data-p202-range-field="to"<?php echo $isCustom ? '' : ' disabled'; ?>>
            <?php /* Only true without JavaScript, which is the only case
                     where the fields above are really disabled; p202-ui.js
                     hides it and lets a date be typed directly. */ ?>
            <?php if (!$isCustom) { ?>
                <span class="form-text" data-p202-range-hint>Choose <em>Custom Date</em> to set these.</span>
            <?php } ?>

            <label class="form-label mb-0" for="app_id">App</label>
            <?php
            // An app_id with no option of its own would leave the menu on
            // "All apps" over a report that IS still filtered — and the next
            // Apply would submit the empty value and widen it without a word.
            // It happens: an app deleted since the link was made, one past
            // RegisteredApps::MAX, a postback for an app that was never
            // registered, or an apps read that failed.
            $appIds = array_map(static fn (array $a): string => (string)($a['app_id'] ?? ''), $apps);
            $unlisted = $filters['app_id'] !== '' && !in_array((string)$filters['app_id'], $appIds, true);
            ?>
            <select class="form-select form-select-sm" id="app_id" name="app_id" style="width:auto">
                <option value="">All apps</option>
                <?php if ($unlisted) { ?>
                    <option value="<?php echo $e($filters['app_id']); ?>" selected>
                        <?php echo $e($filters['app_id']); ?> (not in your list)
                    </option>
                <?php } ?>
                <?php foreach ($apps as $app) { ?>
                    <option value="<?php echo (int)$app['app_id']; ?>"<?php echo (string)$filters['app_id'] === (string)$app['app_id'] ? ' selected' : ''; ?>><?php echo $e($app['app_name'] ?? ''); ?></option>
                <?php } ?>
            </select>

            <label class="form-label mb-0" for="signature">Signature</label>
            <select class="form-select form-select-sm" id="signature" name="signature" style="width:auto">
                <option value="">Any</option>
                <?php /* The states the column really holds, from the enum that
                         defines them, so the menu and the controller's
                         validation cannot offer and accept different sets. */ ?>
                <?php foreach (\Api\V3\Attribution\SignatureState::values() as $key) { ?>
                    <option value="<?php echo $e($key); ?>"<?php echo $filters['signature'] === $key ? ' selected' : ''; ?>><?php echo $e(ucfirst($key)); ?></option>
                <?php } ?>
            </select>

            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
        <div class="p202-table-toolbar__aside">
            <span class="text-secondary small">
                <?php /* An <a>, as the kit renders it: a <span> takes no
                         focus, so the only explanation of the UTC rule would
                         be reachable by mouse alone, and .p202-help's own
                         stylesheet carries a :focus rule for an element that
                         can never have it. */ ?>
                <a href="#" class="p202-help" role="button" data-bs-toggle="tooltip"
                   aria-label="Why these dates are UTC"
                   title="Postbacks are grouped into whole UTC days, so these dates are UTC — unlike the click reports, which use your account timezone."><i class="bi bi-clock"></i></a>
                UTC days
            </span>
        </div>
    </form>

<?php } ?>

<?php if ($view === 'report') { ?>
    <?php $report = $mobileReport['report'];
    $totals = $mobileReport['totals']; ?>
    <?php if ($report !== null && $totals === null) { ?>
        <?php echo $empty(
            'bi-exclamation-triangle',
            'The totals could not be read',
            'The table below is still the report for this range; only the figures above it are missing.'
        ); ?>
    <?php } ?>
    <?php if ($report === null) { ?>
        <?php echo $empty(
            'bi-exclamation-triangle',
            'The report could not be read',
            'The message above says why. This is not a statement that there are no postbacks.'
        ); ?>
    <?php } else { ?>
        <?php if ($totals !== null) { ?>
        <div class="p202-tiles">
            <div class="p202-tile">
                <div class="p202-tile__label">Postbacks</div>
                <div class="p202-tile__value"><?php echo $num($totals['postbacks']); ?></div>
                <div class="p202-tile__sub">all signatures</div>
            </div>
            <div class="p202-tile is-good">
                <div class="p202-tile__label">Installs</div>
                <div class="p202-tile__value"><?php echo $num($totals['installs']); ?></div>
                <div class="p202-tile__sub">
                    <?php echo $report['trusted'] === 'verified-only' ? 'verified only' : 'as filtered'; ?>
                </div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Re-downloads</div>
                <div class="p202-tile__value"><?php echo $num($totals['redownloads']); ?></div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Re-engagements</div>
                <div class="p202-tile__value"><?php echo $num($totals['reengagements']); ?></div>
            </div>
            <div class="p202-tile is-bad">
                <div class="p202-tile__label">Losses</div>
                <div class="p202-tile__value"><?php echo $num($totals['losses']); ?></div>
                <div class="p202-tile__sub">did not win</div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Decoded revenue</div>
                <div class="p202-tile__value"><?php echo $e($money($totals['revenue'])); ?></div>
                <?php /* The counters above are the whole window, asked of the
                         API ungrouped. Revenue is the one summed over the
                         groups on the page, so it alone shrinks when the
                         report is truncated — and says so. */ ?>
                <?php if ($report['truncated']) { ?>
                    <div class="p202-tile__sub">listed groups only</div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if ($totals !== null && $report['trusted'] !== 'verified-only') { ?>
            <?php /* The opposite of reassurance: a signature filter turns the
                     trust gate OFF, so these numbers are computed over rows
                     that failed verification, could not be verified, or were
                     minted by any phone in Developer Mode. The banner used to
                     be hidden in exactly this case. */ ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">
                    Every figure here counts <strong><?php echo $e($filters['signature']); ?></strong>-signature postbacks,
                    not signature-verified ones — the signature filter replaces the usual trust gate.
                    <a href="<?php echo $e($link(['signature' => null])); ?>">Drop the filter</a> to count verified postbacks only.
                </div>
            </div>
        <?php } elseif ($totals !== null && $totals['postbacks'] > $totals['signature_valid_count']) { ?>
            <div class="alert alert-info p202-flash" role="status">
                <i class="bi bi-info-circle"></i>
                <div class="p202-flash__body">
                    Installs, losses and revenue count <strong>signature-verified postbacks only</strong>.
                    Of <?php echo $num($totals['postbacks']); ?> postbacks in this range,
                    <?php echo $num($totals['signature_valid_count']); ?> verified,
                    <?php echo $num($totals['signature_invalid_count']); ?> failed verification and
                    <?php echo $num($totals['signature_unverified_count']); ?> could not be verified;
                    <?php echo $num($totals['signature_development_count']); ?> of them
                    <?php echo $totals['signature_development_count'] === 1 ? 'was' : 'were'; ?> development-signed,
                    which is a class the others overlap rather than a fourth share of the total.
                    Filter by signature above to count a different class.
                </div>
            </div>
        <?php } ?>

        <section class="p202-section">
            <div class="p202-table-toolbar">
                <div class="p202-toolbar">
                    <?php foreach ($mobileReport['groupings'] as $key => $label) { ?>
                        <a class="p202-pill<?php echo $filters['group_by'] === $key ? ' p202-pill--accent' : ''; ?>"
                           href="<?php echo $e($link(['group_by' => $key])); ?>"><?php echo $e($label); ?></a>
                    <?php } ?>
                </div>
                <div class="p202-table-toolbar__aside">
                    <a class="btn btn-secondary btn-sm" href="<?php echo $e($link(['view' => 'report', 'download' => 'csv'])); ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Download to CSV</a>
                </div>
            </div>

            <?php if ($report['groups'] === []) { ?>
                <?php echo $empty(
                    'bi-inbox',
                    'No postbacks in this range',
                    'Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Widen the range, or check that the receivers are reachable.',
                    'Check the receivers',
                    $setupUrl
                ); ?>
            <?php } else { ?>
                <div class="p202-table-wrap">
                    <table class="table table-hover p202-table">
                        <thead>
                            <tr>
                                <th><?php echo $e($mobileReport['groupings'][$filters['group_by']]); ?></th>
                                <th class="num">Postbacks</th>
                                <th class="num">Installs</th>
                                <th class="num">Re-downloads</th>
                                <th class="num">Re-engagements</th>
                                <th class="num">Losses</th>
                                <th class="num">Revenue</th>
                                <th class="num">Verified</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report['groups'] as $group) { ?>
                            <?php $groupRevenue = $C::groupRevenue($group); ?>
                            <tr>
                                <td><?php echo $groupCell($group, $filters['group_by']); ?></td>
                                <td class="num"><?php echo $num($group['postbacks'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['installs'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['redownloads'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['reengagements'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['losses'] ?? 0); ?></td>
                                <td class="num"><?php echo $e($money($groupRevenue)); ?></td>
                                <td class="num"><?php echo $num($group['signature_valid_count'] ?? 0); ?></td>
                            </tr>
                        <?php } ?>
                            <?php if ($totals !== null) { ?>
                            <tr class="p202-table__totals">
                                <td><?php echo $report['truncated'] ? 'Totals for the range' : 'Totals for report'; ?></td>
                                <td class="num"><?php echo $num($totals['postbacks']); ?></td>
                                <td class="num"><?php echo $num($totals['installs']); ?></td>
                                <td class="num"><?php echo $num($totals['redownloads']); ?></td>
                                <td class="num"><?php echo $num($totals['reengagements']); ?></td>
                                <td class="num"><?php echo $num($totals['losses']); ?></td>
                                <td class="num"><?php echo $e($money($totals['revenue'])); ?></td>
                                <td class="num"><?php echo $num($totals['signature_valid_count']); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($report['truncated']) { ?>
                    <p class="text-secondary small"><i class="bi bi-exclamation-triangle"></i>
                        More groups matched than are shown, so the rows below are the busiest ones and
                        <em>Decoded revenue</em> covers only them. The other figures above the table are
                        the whole range. Narrow the range or filter by app to see the rest.</p>
                <?php } ?>
            <?php } ?>
        </section>

        <?php if ($mobileReport['events'] !== []) { ?>
            <section class="p202-panel">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Decoded events</h2>
                    <p class="p202-panel__sub">Conversion values read through the rules on Setup &rsaquo; Mobile Apps.</p>
                    <span class="p202-pill p202-pill--accent"><?php echo count($mobileReport['events']); ?> <?php echo count($mobileReport['events']) === 1 ? 'event' : 'events'; ?></span>
                </div>
                <div class="p202-panel__body">
                    <div class="p202-table-wrap">
                        <table class="table table-hover p202-table">
                            <thead><tr><th>Event</th><th class="num">Postbacks</th><th class="num">Revenue</th></tr></thead>
                            <tbody>
                            <?php foreach ($mobileReport['events'] as $event) { ?>
                                <tr>
                                    <td><?php echo $e($event['name']); ?></td>
                                    <td class="num"><?php echo $num($event['count']); ?></td>
                                    <td class="num"><?php echo $e($money($event['revenue'])); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-secondary small mb-0">Conversion values decode across all three windows, so these counts can exceed installs.</p>
                </div>
            </section>
        <?php } ?>

    <?php } ?>

<?php } elseif ($view === 'postbacks') { ?>
    <?php $rows = $mobileReport['postbacks'];
    $pagination = $mobileReport['pagination']; ?>
    <?php if ($rows === null) { ?>
        <?php echo $empty(
            'bi-exclamation-triangle',
            'The postbacks could not be read',
            'The message above says why. This is not a statement that none have arrived.'
        ); ?>
    <?php } elseif ($rows === []) { ?>
        <?php echo $empty(
            'bi-inbox',
            'No postbacks in this range',
            'Widen the range, or check that the receivers are reachable.',
            'Check the receivers',
            $setupUrl
        ); ?>
    <?php } else { ?>
        <section class="p202-section">
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead>
                        <tr>
                            <th>Received</th><th>App</th><th>Protocol</th><th>Ad network</th>
                            <th>Source</th><th>Type</th><th class="num">Value</th><th>Won</th>
                            <th>Signature</th><th>Transaction</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?php echo $e(gmdate('Y-m-d H:i', (int)($row['received_at'] ?? 0))); ?></td>
                            <td><?php
                                // A bare App Store id is not something anyone
                                // reads; the registered name is, and this page
                                // already holds the list it comes from.
                                $rowAppId = (string)($row['app_id'] ?? '');
                                $rowAppName = $appNames[$rowAppId] ?? '';
                                echo $rowAppName === ''
                                    ? $e($rowAppId)
                                    : $e($rowAppName) . ' <span class="text-secondary">' . $e($rowAppId) . '</span>';
                            ?></td>
                            <td><?php echo $e(trim((string)($row['protocol'] ?? '') . ' ' . (string)($row['version'] ?? ''))); ?></td>
                            <td><?php echo $e((string)($row['ad_network_id'] ?? '')); ?></td>
                            <td><?php
                                $rowSource = array_filter([
                                    (string)($row['source_identifier'] ?? ''),
                                    ($row['campaign_id'] ?? null) === null ? '' : (string)$row['campaign_id'],
                                ], static fn (string $part): bool => $part !== '');
                                echo $rowSource === [] ? $notGiven : $e(implode(' · ', $rowSource));
                                ?></td>
                            <td><?php
                                // The report counts the first conversion
                                // window only, so a later one is the answer
                                // to "why is this row not in the totals".
                                $conversionWindow = (int)($row['postback_sequence_index'] ?? 0);
                                echo $e((string)($row['conversion_type'] ?? '')), $conversionWindow > 0
                                    ? ' <span class="text-secondary">window ' . $e((string)$conversionWindow) . '</span>'
                                    : '';
                            ?></td>
                            <td class="num"><?php
                                $fine = $row['conversion_value'] ?? null;
                                $coarse = (string)($row['coarse_conversion_value'] ?? '');
                                echo $e($fine === null ? ($coarse === '' ? '' : $coarse) : (string)$fine);
                            ?></td>
                            <td><?php
                                $won = $row['did_win'] ?? null;
                                echo $won === null ? '<span class="text-secondary">not said</span>' : ((int)$won === 1 ? 'yes' : 'no');
                            ?></td>
                            <td><span class="<?php echo $e($signatureTone($row['signature_state'] ?? '')); ?>"><?php echo $e((string)($row['signature_state'] ?? '')); ?></span></td>
                            <?php /* The key the ad network's own report is
                                     keyed on, so a disagreement about one
                                     postback can be taken to them. */ ?>
                            <td class="font-monospace small"><?php echo $e((string)($row['transaction_id'] ?? '')); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php /* Rows, not postbacks: list() counts stored rows while the
                     Report tab counts one per replayed postback, so calling
                     both "postbacks" makes the two tabs look like they
                     disagree about the same number. */ ?>
            <p class="text-secondary small">
                <?php echo $num($pagination['rows']); ?>
                <?php echo $pagination['rows'] === 1 ? 'row' : 'rows'; ?> in this range.
                A postback replayed with different unsigned values is stored as its own row,
                so this can exceed the Report tab's postback count.
            </p>

            <?php if ($pagination['pages'] > 1) { ?>
                <nav class="mt-3" aria-label="Pages">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $page = $pagination['page'];
                        $pages = $pagination['pages'];
                        $pageWindow = range(max(1, $page - 2), min($pages, $page + 2));
                        ?>
                        <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => max(1, $page - 1)])); ?>"<?php echo $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&lsaquo;</a>
                        </li>
                        <?php foreach ($pageWindow as $n) { ?>
                            <li class="page-item<?php echo $n === $page ? ' active' : ''; ?>"<?php echo $n === $page ? ' aria-current="page"' : ''; ?>>
                                <a class="page-link" href="<?php echo $e($link(['page' => $n])); ?>"><?php echo (int)$n; ?></a>
                            </li>
                        <?php } ?>
                        <li class="page-item<?php echo $page >= $pages ? ' disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $e($link(['page' => min($pages, $page + 1)])); ?>"<?php echo $page >= $pages ? ' tabindex="-1" aria-disabled="true"' : ''; ?>>&rsaquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php } ?>
        </section>
    <?php } ?>

<?php } ?>

<?php template_bottom(); ?>
