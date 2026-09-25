<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps: the Report tab — /apps/report, rendered.
 *
 * One layout per platform, because the two platforms' numbers are not the
 * same kind of number and the page says so rather than pretending:
 *
 *  - iOS: Apple's postbacks — delayed, aggregate and privacy-thresholded —
 *    decoded through the SKAN encodings, with ambiguous_encoding counted
 *    where an edited value had two meanings a device could have used.
 *  - Android: the installs the SDK reported, by install (a cohort), with the
 *    goals they reached, their match states and Play Integrity verdicts.
 *  - Both: the metrics that mean the same on both, one row per platform in
 *    each group, and a combined figure labelled as such.
 *
 * Headline figures count trusted signals only (CLAUDE.md #16): signature-
 * verified postbacks, attributed installs; every other class is beside them.
 *
 * @var array<string, mixed> $mobileReport
 * @var array<string, mixed> $filters
 * @var string $platform
 * @var class-string $C
 * @var callable $e
 * @var callable $num
 * @var callable $money
 * @var callable $link
 * @var callable $empty
 * @var callable $groupCell
 * @var string $setupUrl
 */

$report = $mobileReport['report'];
$totals = $mobileReport['totals'];
$groupBy = (string)$filters['group_by'];
$groupLabel = $mobileReport['groupings'][$groupBy] ?? 'Group';
$states = static function (mixed $counts): array {
    $out = [];
    foreach ((array)$counts as $state => $n) {
        $out[] = ['state' => str_replace('_', ' ', (string)$state), 'n' => (int)$n];
    }
    usort($out, static fn (array $a, array $b): int => $b['n'] <=> $a['n'] ?: strcmp($a['state'], $b['state']));
    return $out;
};
?>
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
        'The message above says why. This is not a statement that there are no installs.'
    ); ?>
<?php } else { ?>

    <?php if ($totals !== null && $platform === 'all') {
        $combined = $totals['combined']; ?>
        <div class="p202-tiles">
            <div class="p202-tile is-good">
                <div class="p202-tile__label">Installs</div>
                <div class="p202-tile__value"><?php echo $num($combined['installs']); ?></div>
                <div class="p202-tile__sub">both platforms · iOS <?php echo $num($totals['ios']['installs'] ?? 0); ?> · Android <?php echo $num($totals['android']['installs'] ?? 0); ?></div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Goals reached</div>
                <div class="p202-tile__value"><?php echo $num($combined['goals_reached']); ?></div>
                <div class="p202-tile__sub">both platforms</div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Revenue</div>
                <div class="p202-tile__value"><?php echo $e($money($combined['revenue'])); ?></div>
                <div class="p202-tile__sub">iOS decoded + Android credited</div>
            </div>
            <div class="p202-tile is-bad">
                <div class="p202-tile__label">Refuted</div>
                <div class="p202-tile__value"><?php echo $num($combined['refuted_count']); ?></div>
                <div class="p202-tile__sub">checked and found false</div>
            </div>
        </div>
        <div class="alert alert-info p202-flash" role="status">
            <i class="bi bi-info-circle"></i>
            <div class="p202-flash__body">
                iOS figures are Apple's postbacks: they arrive a day or more after the install, only for installs a campaign won, and Apple withholds values below its privacy thresholds. Android figures are every install the SDK reported, as it happened. Choose one platform above for its own dimensions.
            </div>
        </div>
    <?php } elseif ($totals !== null && $platform === 'android') {
        // `installs` is always the trusted (attributed) count. Under an
        // explicit trust filter the rows are that class — refuted or
        // unvouched ones hold no trusted install — so the tile counts every
        // install the filter selected (`received`), and says which class.
        $asFiltered = $report['trusted'] !== 'trusted-only'; ?>
        <div class="p202-tiles">
            <div class="p202-tile is-good">
                <div class="p202-tile__label">Installs</div>
                <div class="p202-tile__value"><?php echo $num($asFiltered ? $totals['received'] : $totals['installs']); ?></div>
                <div class="p202-tile__sub"><?php echo $asFiltered ? $e((string)$filters['trusted']) . ' installs, as filtered' : 'attributed to a click'; ?></div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Organic</div>
                <div class="p202-tile__value"><?php echo $num($totals['organic']); ?></div>
                <div class="p202-tile__sub">no campaign</div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Goals reached</div>
                <div class="p202-tile__value"><?php echo $num($totals['goals_reached']); ?></div>
                <div class="p202-tile__sub">by <?php echo $report['trusted'] === 'trusted-only' ? 'attributed' : 'these'; ?> installs</div>
            </div>
            <div class="p202-tile">
                <div class="p202-tile__label">Revenue</div>
                <div class="p202-tile__value"><?php echo $e($money($totals['revenue'])); ?></div>
                <div class="p202-tile__sub">credited to campaigns</div>
            </div>
            <div class="p202-tile is-bad">
                <div class="p202-tile__label">Refuted</div>
                <div class="p202-tile__value"><?php echo $num($totals['refuted_count']); ?></div>
                <div class="p202-tile__sub">forged or implausible</div>
            </div>
        </div>
        <?php if ($report['trusted'] !== 'trusted-only') { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">
                    Every figure here counts <strong><?php echo $e($filters['trusted']); ?></strong> installs and the goals they reached, not attributed ones.
                    <a href="<?php echo $e($link(['trusted' => null])); ?>">Drop the filter</a> to count attributed installs only.
                </div>
            </div>
        <?php } elseif ((int)$totals['received'] > (int)$totals['installs']) { ?>
            <div class="alert alert-info p202-flash" role="status">
                <i class="bi bi-info-circle"></i>
                <div class="p202-flash__body">
                    Installs, goals and revenue count <strong>installs attributed to a click</strong>. Of <?php echo $num($totals['received']); ?> installs the SDK reported,
                    <?php echo $num($totals['installs']); ?> were attributed, <?php echo $num($totals['unvouched_count']); ?> nobody could vouch for (organic among them),
                    <?php echo $num($totals['refuted_count']); ?> were refuted and <?php echo $num($totals['pending']); ?> are waiting for their click or their Play Integrity verdict.
                </div>
            </div>
        <?php } ?>
    <?php } elseif ($totals !== null) { ?>
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
                    <?php echo $report['trusted'] === 'trusted-only' ? 'verified only' : 'as filtered'; ?>
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
                <div class="p202-tile__value"><?php echo $e($money($totals['revenue'] ?? 0)); ?></div>
                <?php if ((int)($totals['ambiguous_encoding'] ?? 0) > 0) { ?>
                    <div class="p202-tile__sub"><?php echo $num($totals['ambiguous_encoding']); ?> ambiguous, credited to neither meaning</div>
                <?php } ?>
            </div>
        </div>

        <?php if ($report['trusted'] !== 'trusted-only') { ?>
            <?php /* The opposite of reassurance: a signature filter turns the
                     trust gate OFF, so these numbers are computed over rows
                     that failed verification, could not be verified, or were
                     minted by any phone in Developer Mode. */ ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">
                    Every figure here counts <strong><?php echo $e($filters['signature']); ?></strong>-signature postbacks,
                    not signature-verified ones — the signature filter replaces the usual trust gate.
                    <a href="<?php echo $e($link(['signature' => null])); ?>">Drop the filter</a> to count verified postbacks only.
                </div>
            </div>
        <?php } elseif ($totals['postbacks'] > $totals['trusted_count']) { ?>
            <div class="alert alert-info p202-flash" role="status">
                <i class="bi bi-info-circle"></i>
                <div class="p202-flash__body">
                    Installs, losses and revenue count <strong>signature-verified postbacks only</strong>.
                    Of <?php echo $num($totals['postbacks']); ?> postbacks in this range,
                    <?php echo $num($totals['trusted_count']); ?> verified,
                    <?php echo $num($totals['refuted_count']); ?> failed verification and
                    <?php echo $num($totals['unvouched_count']); ?> could not be verified;
                    <?php echo $num($totals['test_count']); ?> of them
                    <?php echo $totals['test_count'] === 1 ? 'was' : 'were'; ?> development-signed,
                    which is a class the others overlap rather than a fourth share of the total.
                    Filter by signature above to count a different class.
                </div>
            </div>
        <?php } ?>
    <?php } ?>

    <section class="p202-section">
        <div class="p202-table-toolbar">
            <div class="p202-toolbar">
                <?php foreach ($mobileReport['groupings'] as $key => $label) { ?>
                    <a class="p202-pill<?php echo $groupBy === $key ? ' p202-pill--accent' : ''; ?>"
                       href="<?php echo $e($link(['group_by' => $key])); ?>"><?php echo $e($label); ?></a>
                <?php } ?>
            </div>
            <div class="p202-table-toolbar__aside">
                <a class="btn btn-secondary btn-sm" href="<?php echo $e($link(['view' => 'report', 'download' => 'csv'])); ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Download to CSV</a>
            </div>
        </div>

        <?php if ($report['groups'] === []) { ?>
            <?php echo $platform === 'ios'
                ? $empty('bi-inbox', 'No postbacks in this range',
                    'Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Widen the range, or check that the receivers are reachable.',
                    'Check the receivers', $setupUrl)
                : $empty('bi-inbox', 'No installs in this range',
                    'Widen the range, or check the app\'s setup: its campaigns\' store link and the SDK\'s app token.',
                    'Open Setup › Mobile Apps', $setupUrl); ?>
        <?php } elseif ($platform === 'ios') { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead>
                        <tr>
                            <th><?php echo $e($groupLabel); ?></th>
                            <th class="num">Postbacks</th>
                            <th class="num">Installs</th>
                            <th class="num">Re-downloads</th>
                            <th class="num">Re-engagements</th>
                            <th class="num">Losses</th>
                            <th class="num">Revenue</th>
                            <th class="num">Ambiguous</th>
                            <th class="num">Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['groups'] as $group) { ?>
                        <tr>
                            <td><?php echo $groupCell($group, $groupBy); ?></td>
                            <td class="num"><?php echo $num($group['postbacks'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['installs'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['redownloads'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['reengagements'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['losses'] ?? 0); ?></td>
                            <td class="num"><?php echo $e($money($C::groupRevenue($group))); ?></td>
                            <td class="num"><?php echo $num($group['ambiguous_encoding'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['trusted_count'] ?? 0); ?></td>
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
                            <td class="num"><?php echo $e($money($totals['revenue'] ?? 0)); ?></td>
                            <td class="num"><?php echo $num($totals['ambiguous_encoding'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($totals['trusted_count']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small"><i class="bi bi-info-circle"></i>
                <em>Ambiguous</em> counts postbacks whose value changed meaning within the <?php echo (int)\Api\V3\Apps\Apple\SkanEncodingTimeline::HORIZON_DAYS; ?> days before they arrived, where the meanings disagree: they are credited to neither.</p>
        <?php } elseif ($platform === 'android') { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead>
                        <tr>
                            <th><?php echo $e($groupLabel); ?></th>
                            <?php if ($groupBy === 'goal') { ?>
                                <th class="num">Installs reaching it</th>
                                <th class="num">Unvouched</th>
                                <th class="num">Times reached</th>
                            <?php } else { ?>
                                <th class="num">Received</th>
                                <th class="num">Installs</th>
                                <th class="num">Organic</th>
                                <th class="num">Pending</th>
                                <th class="num">Refuted</th>
                                <th class="num">Unvouched</th>
                                <th class="num">Goals reached</th>
                            <?php } ?>
                            <th class="num">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['groups'] as $group) { ?>
                        <tr>
                            <td><?php echo $groupCell($group, $groupBy); ?></td>
                            <?php if ($groupBy === 'goal') { ?>
                                <td class="num"><?php echo $num($group['installs'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['unvouched_count'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['goals_reached'] ?? 0); ?></td>
                            <?php } else { ?>
                                <td class="num"><?php echo $num($group['received'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['installs'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['organic'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['pending'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['refuted_count'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['unvouched_count'] ?? 0); ?></td>
                                <td class="num"><?php echo $num($group['goals_reached'] ?? 0); ?></td>
                            <?php } ?>
                            <td class="num"><?php echo $e($money($C::groupRevenue($group))); ?></td>
                        </tr>
                    <?php } ?>
                    <?php if ($totals !== null && $groupBy !== 'goal') { ?>
                        <tr class="p202-table__totals">
                            <td><?php echo $report['truncated'] ? 'Totals for the range' : 'Totals for report'; ?></td>
                            <td class="num"><?php echo $num($totals['received']); ?></td>
                            <td class="num"><?php echo $num($totals['installs']); ?></td>
                            <td class="num"><?php echo $num($totals['organic']); ?></td>
                            <td class="num"><?php echo $num($totals['pending']); ?></td>
                            <td class="num"><?php echo $num($totals['refuted_count']); ?></td>
                            <td class="num"><?php echo $num($totals['unvouched_count']); ?></td>
                            <td class="num"><?php echo $num($totals['goals_reached']); ?></td>
                            <td class="num"><?php echo $e($money($totals['revenue'])); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($groupBy === 'goal') { ?>
                <p class="text-secondary small"><i class="bi bi-info-circle"></i> The <a href="<?php echo $e($link(['view' => 'funnel'])); ?>">Funnel</a> tab puts one app's goals in order.</p>
            <?php } ?>
        <?php } else { ?>
            <div class="p202-table-wrap">
                <table class="table table-hover p202-table">
                    <thead>
                        <tr>
                            <th><?php echo $e($groupLabel); ?></th>
                            <?php if ($groupBy !== 'platform') { ?><th>Platform</th><?php } ?>
                            <th class="num">Installs</th>
                            <th class="num">Goals reached</th>
                            <th class="num">Revenue</th>
                            <th class="num">Refuted</th>
                            <th class="num">Unvouched</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['groups'] as $group) { ?>
                        <tr>
                            <td><?php echo $groupCell($group, $groupBy); ?></td>
                            <?php if ($groupBy !== 'platform') { ?><td><?php echo $e($C::PLATFORMS[(string)($group['platform'] ?? '')] ?? ''); ?></td><?php } ?>
                            <td class="num"><?php echo $num($group['installs'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['goals_reached'] ?? 0); ?></td>
                            <td class="num"><?php echo $e($money($C::groupRevenue($group))); ?></td>
                            <td class="num"><?php echo $num($group['refuted_count'] ?? 0); ?></td>
                            <td class="num"><?php echo $num($group['unvouched_count'] ?? 0); ?></td>
                        </tr>
                    <?php } ?>
                    <?php if ($totals !== null) { ?>
                        <tr class="p202-table__totals">
                            <td>Both platforms</td>
                            <?php if ($groupBy !== 'platform') { ?><td></td><?php } ?>
                            <td class="num"><?php echo $num($totals['combined']['installs']); ?></td>
                            <td class="num"><?php echo $num($totals['combined']['goals_reached']); ?></td>
                            <td class="num"><?php echo $e($money($totals['combined']['revenue'])); ?></td>
                            <td class="num"><?php echo $num($totals['combined']['refuted_count']); ?></td>
                            <td class="num"><?php echo $num($totals['combined']['unvouched_count']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($report['groups'] !== [] && $report['truncated']) { ?>
            <p class="text-secondary small"><i class="bi bi-exclamation-triangle"></i>
                More groups matched than are shown, so the rows are the <?php echo $groupBy === 'day' ? 'newest' : 'busiest'; ?> ones.
                The figures above the table and its totals row are the whole range. Narrow the range or filter by app to see the rest.</p>
        <?php } ?>
    </section>

    <?php if ($platform === 'android' && $totals !== null) {
        $matchStates = $states($totals['match_states'] ?? []);
        $integrityStates = $states($totals['integrity_states'] ?? []); ?>
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <section class="p202-panel">
                    <div class="p202-panel__head">
                        <h2 class="p202-panel__title">How installs were matched</h2>
                        <p class="p202-panel__sub">Every install in the range, by what the intake concluded.</p>
                    </div>
                    <div class="p202-panel__body">
                        <?php if ($matchStates === []) { ?>
                            <p class="text-body-secondary mb-0">No installs in this range.</p>
                        <?php } else { ?>
                            <div class="p202-table-wrap">
                                <table class="table p202-table">
                                    <thead><tr><th>Match state</th><th class="num">Installs</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($matchStates as $row) { ?>
                                        <tr><td><a href="<?php echo $e($link(['group_by' => 'match-state'])); ?>"><?php echo $e($row['state']); ?></a></td><td class="num"><?php echo $num($row['n']); ?></td></tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </section>
            </div>
            <div class="col-12 col-lg-6">
                <section class="p202-panel">
                    <div class="p202-panel__head">
                        <h2 class="p202-panel__title">Play Integrity verdicts</h2>
                        <p class="p202-panel__sub">Not requested unless an app turned Play Integrity on.</p>
                    </div>
                    <div class="p202-panel__body">
                        <?php if ($integrityStates === []) { ?>
                            <p class="text-body-secondary mb-0">No installs in this range.</p>
                        <?php } else { ?>
                            <div class="p202-table-wrap">
                                <table class="table p202-table">
                                    <thead><tr><th>Verdict</th><th class="num">Installs</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($integrityStates as $row) { ?>
                                        <tr><td><?php echo $e($row['state']); ?></td><td class="num"><?php echo $num($row['n']); ?></td></tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </section>
            </div>
        </div>
    <?php } ?>

    <?php if ($mobileReport['events'] !== []) { ?>
        <section class="p202-panel">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title"><?php echo $platform === 'ios' ? 'Decoded goals' : 'Goals reached'; ?></h2>
                <p class="p202-panel__sub"><?php echo $platform === 'ios'
                    ? 'Conversion values read through the rules on Setup › Mobile Apps, over the whole range.'
                    : 'What the installs above went on to do, over the whole range.'; ?></p>
                <span class="p202-pill p202-pill--accent"><?php echo count($mobileReport['events']); ?> <?php echo count($mobileReport['events']) === 1 ? 'goal' : 'goals'; ?></span>
            </div>
            <div class="p202-panel__body">
                <div class="p202-table-wrap">
                    <table class="table table-hover p202-table">
                        <thead><tr><th>Goal</th><th class="num"><?php echo $platform === 'ios' ? 'Postbacks' : 'Times reached'; ?></th><th class="num">Revenue</th></tr></thead>
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
                <?php if ($platform === 'ios') { ?>
                    <p class="text-secondary small mb-0">Conversion values decode across all three windows, so these counts can exceed installs.</p>
                <?php } ?>
            </div>
        </section>
    <?php } ?>

<?php } ?>
