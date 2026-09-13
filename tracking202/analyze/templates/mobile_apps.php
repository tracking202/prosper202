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

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn (mixed $v): string => number_format((float)$v);
$currency = (string)$mobileReport['currency'];
$money = static fn (mixed $v): string => (string)dollar_format((float)$v, $currency);

/** App Store id to registered name, for the rows that only carry the id. */
$appNames = [];
foreach ($mobileReport['apps'] as $knownApp) {
    $appNames[(string)$knownApp['app_id']] = (string)($knownApp['app_name'] ?? '');
}

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
    $range = (string)($changes['range'] ?? $filters['range']);
    $custom = $range === $customRange;
    $query = array_filter([
        'view' => $changes['view'] ?? $view,
        'group_by' => $changes['group_by'] ?? $filters['group_by'],
        'range' => $range,
        'from' => $custom ? ($changes['from'] ?? $filters['from']) : null,
        'to' => $custom ? ($changes['to'] ?? $filters['to']) : null,
        'app_id' => $changes['app_id'] ?? $filters['app_id'],
        'signature' => $changes['signature'] ?? $filters['signature'],
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

/* An alert wearing the component class, with its icon: the shape the UI kit
   defines. .p202-flash carries no colour of its own. */
$flashMarkup = static function (string $kind, string $text) use ($e): string {
    [$variant, $icon] = [
        'ok' => ['alert-success', 'bi-check-circle'],
        'bad' => ['alert-danger', 'bi-x-circle'],
        'warn' => ['alert-warning', 'bi-exclamation-triangle'],
    ][$kind] ?? ['alert-info', 'bi-info-circle'];

    return '<div class="alert ' . $variant . ' p202-flash" role="status"><i class="bi ' . $icon . '"></i>'
        . '<div class="p202-flash__body">' . $e($text) . '</div></div>';
};

/** A value the report did not receive, said rather than left blank. */
$notGiven = '<span class="text-secondary">not given</span>';

/**
 * What the first column holds, per grouping.
 *
 * Which field that is comes from the controller's GROUP_KEYS, the same table
 * the CSV reads, so the two columns cannot come out of different fields.
 */
$groupCell = static function (array $group, string $groupBy) use ($e, $notGiven): string {
    switch ($groupBy) {
        case 'app':
            $name = trim((string)($group['app_name'] ?? ''));
            $id = (int)($group['app_id'] ?? 0);
            return $name !== ''
                ? $e($name) . ' <span class="text-secondary">' . $e((string)$id) . '</span>'
                : $e((string)$id);
        case 'source':
            // '0' is a real source identifier, so an empty part is named
            // rather than left to array_filter's idea of falsiness.
            $parts = array_filter([
                (string)($group['source_identifier'] ?? ''),
                ($group['campaign_id'] ?? null) === null ? '' : (string)$group['campaign_id'],
            ], static fn (string $part): bool => $part !== '');
            return $parts === [] ? $notGiven : $e(implode(' · ', $parts));
        default:
            $key = \Tracking202\Analyze\MobileAppsReportController::GROUP_KEYS[$groupBy] ?? '';
            $value = (string)($group[$key] ?? '');
            return $value === '' ? $notGiven : $e($value);
    }
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
    echo $flashMarkup($flash['kind'], $flash['text']);
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

            <?php /* Disabled off a custom window, so a browser does not submit
                     the window it is only displaying; p202-ui.js enables them
                     the moment Custom Date is chosen. */ ?>
            <label class="form-label mb-0" for="from">From</label>
            <input class="form-control form-control-sm" style="width:auto" type="date"
                   id="from" name="from" value="<?php echo $e($filters['from']); ?>"
                   data-p202-range-field<?php echo $isCustom ? '' : ' disabled'; ?>>
            <label class="form-label mb-0" for="to">To</label>
            <input class="form-control form-control-sm" style="width:auto" type="date"
                   id="to" name="to" value="<?php echo $e($filters['to']); ?>"
                   data-p202-range-field<?php echo $isCustom ? '' : ' disabled'; ?>>
            <?php if (!$isCustom) { ?>
                <span class="form-text">Choose <em>Custom Date</em> to set these.</span>
            <?php } ?>

            <label class="form-label mb-0" for="app_id">App</label>
            <select class="form-select form-select-sm" id="app_id" name="app_id" style="width:auto">
                <option value="">All apps</option>
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
                <span class="p202-help" data-bs-toggle="tooltip" title="Postbacks are grouped into whole UTC days, so these dates are UTC — unlike the click reports, which use your account timezone."><i class="bi bi-clock"></i></span>
                UTC days
            </span>
        </div>
    </form>

<?php } ?>

<?php if ($view === 'report') { ?>
    <?php $report = $mobileReport['report'];
    $totals = $mobileReport['totals']; ?>
    <?php if ($report === null) { ?>
        <div class="p202-empty">
            <i class="bi bi-exclamation-triangle p202-empty__icon"></i>
            <strong class="p202-empty__title">The report could not be read</strong>
            <div>The message above says why. This is not a statement that there are no postbacks.</div>
        </div>
    <?php } else { ?>
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
            </div>
        </div>

        <?php if ($report['trusted'] === 'verified-only' && $totals['postbacks'] > $totals['signature_valid_count']) { ?>
            <div class="alert alert-info p202-flash" role="status">
                <i class="bi bi-info-circle"></i>
                <div class="p202-flash__body">
                    Installs, losses and revenue count <strong>signature-verified postbacks only</strong>.
                    Of <?php echo $num($totals['postbacks']); ?> postbacks in this range,
                    <?php echo $num($totals['signature_valid_count']); ?> verified,
                    <?php echo $num($totals['signature_invalid_count']); ?> failed verification,
                    <?php echo $num($totals['signature_unverified_count']); ?> could not be verified and
                    <?php echo $num($totals['signature_development_count']); ?> were development-signed.
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
                <div class="p202-empty">
                    <i class="bi bi-inbox p202-empty__icon"></i>
                    <strong class="p202-empty__title">No postbacks in this range</strong>
                    <div>Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Widen the range, or check the receivers on Setup &rsaquo; Mobile Apps.</div>
                </div>
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
                            <?php
                            $groupRevenue = 0.0;
                            foreach ((array)($group['events'] ?? []) as $event) {
                                $groupRevenue += (float)($event['revenue'] ?? 0);
                            }
                            ?>
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
                            <tr class="p202-table__totals">
                                <td>Totals for report</td>
                                <td class="num"><?php echo $num($totals['postbacks']); ?></td>
                                <td class="num"><?php echo $num($totals['installs']); ?></td>
                                <td class="num"><?php echo $num($totals['redownloads']); ?></td>
                                <td class="num"><?php echo $num($totals['reengagements']); ?></td>
                                <td class="num"><?php echo $num($totals['losses']); ?></td>
                                <td class="num"><?php echo $e($money($totals['revenue'])); ?></td>
                                <td class="num"><?php echo $num($totals['signature_valid_count']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if ($report['truncated']) { ?>
                    <p class="text-secondary small"><i class="bi bi-exclamation-triangle"></i> More groups matched than are shown. Narrow the range or filter by app to see the rest.</p>
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
        <div class="p202-empty">
            <i class="bi bi-exclamation-triangle p202-empty__icon"></i>
            <strong class="p202-empty__title">The postbacks could not be read</strong>
            <div>The message above says why. This is not a statement that none have arrived.</div>
        </div>
    <?php } elseif ($rows === []) { ?>
        <div class="p202-empty">
            <i class="bi bi-inbox p202-empty__icon"></i>
            <strong class="p202-empty__title">No postbacks in this range</strong>
            <div>Widen the range, or check the receivers on Setup &rsaquo; Mobile Apps.</div>
        </div>
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

            <p class="text-secondary small"><?php echo $num($pagination['total']); ?> <?php echo $pagination['total'] === 1 ? 'postback' : 'postbacks'; ?> in this range.</p>

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
