<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps: the Funnel tab — one Android app's goals in order
 * (the install goal, then each goal after the ones it waits for), with the
 * installs that reached each step in the range, as a share of the first
 * step and of the step it follows (the goal it waits for).
 *
 * Counts are trusted installs (attributed to a click), with the unvouched
 * beside them (CLAUDE.md #16). An iOS app has no funnel to read — Apple's
 * postback carries one conversion value, the highest step the device
 * reached, not each step — so its view says so and shows the decoded goals.
 *
 * @var array<string, mixed> $mobileReport
 * @var callable $e
 * @var callable $num
 * @var callable $pct
 * @var callable $money
 * @var callable $link
 * @var callable $empty
 * @var string $setupUrl
 */

$funnelApp = $mobileReport['funnelApp'];
$steps = $mobileReport['funnel'];
?>
<?php if ($funnelApp === null) { ?>
    <?php if ($mobileReport['funnelApps'] === []) { ?>
        <?php echo $empty('bi-funnel', 'No Android app to read a funnel from',
            'A funnel is the goals an Android app\'s installs reach, in order. Register an Android app and give it goals on Setup › Mobile Apps.',
            'Open Setup › Mobile Apps', $setupUrl); ?>
    <?php } else { ?>
        <section class="p202-panel">
            <div class="p202-panel__head">
                <h2 class="p202-panel__title">Choose an app</h2>
                <p class="p202-panel__sub">A funnel is one app's goals in order.</p>
            </div>
            <div class="p202-panel__body">
                <ul class="p202-list">
                    <?php foreach ($mobileReport['funnelApps'] as $app) { ?>
                        <li class="p202-list__item">
                            <span class="p202-list__name"><a href="<?php echo $e($link(['registration_id' => (string)$app['registration_id']])); ?>"><?php echo $e($app['app_name']); ?></a></span>
                            <span class="p202-list__meta">Android · <?php echo $e($app['app_key']); ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </section>
    <?php } ?>
<?php } elseif ((string)$funnelApp['platform'] !== 'android') {
    $events = $mobileReport['funnelEvents']; ?>
    <div class="alert alert-info p202-flash" role="status">
        <i class="bi bi-info-circle"></i>
        <div class="p202-flash__body">
            <?php echo $e($funnelApp['app_name']); ?> is an iOS app. Apple's postback carries one conversion value — the step the device reached last — not each step it passed, so there is no funnel to read. These are the goals its postbacks decoded to in the range.
        </div>
    </div>
    <?php if ($events === null) { ?>
        <?php echo $empty('bi-exclamation-triangle', 'The decoded goals could not be read', 'The message above says why.'); ?>
    <?php } elseif ($events === []) { ?>
        <?php echo $empty('bi-inbox', 'No decoded postbacks in this range', 'Widen the range, or check the app\'s conversion values on Setup › Mobile Apps.', 'Open Setup › Mobile Apps', $setupUrl . '?app=' . (int)$funnelApp['registration_id']); ?>
    <?php } else { ?>
        <div class="p202-table-wrap">
            <table class="table table-hover p202-table">
                <thead><tr><th>Goal</th><th class="num">Postbacks</th><th class="num">Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event) { ?>
                    <tr><td><?php echo $e($event['name']); ?></td><td class="num"><?php echo $num($event['count']); ?></td><td class="num"><?php echo $e($money($event['revenue'])); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
<?php } elseif ($steps === null) { ?>
    <?php echo $empty('bi-exclamation-triangle', 'The funnel could not be read', 'The message above says why. This is not a statement that nothing was reached.'); ?>
<?php } elseif ($steps === []) { ?>
    <?php echo $empty('bi-flag', 'This app has no goals yet', 'Give it goals — a tutorial, a level, a purchase — and each becomes a step here.', 'Add goals', $setupUrl . '?app=' . (int)$funnelApp['registration_id'] . '#goals'); ?>
<?php } else { ?>
    <section class="p202-panel" id="funnel">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title"><?php echo $e($funnelApp['app_name']); ?></h2>
            <p class="p202-panel__sub">Installs attributed to a click in the range, and the goals they went on to reach, in order.</p>
        </div>
        <div class="p202-panel__body">
            <div class="p202-table-wrap">
                <table class="table p202-table">
                    <thead>
                        <tr>
                            <th>Step</th>
                            <th class="num">Installs</th>
                            <th class="num">Of the first</th>
                            <th class="num">Of the step it follows</th>
                            <th class="num">Unvouched</th>
                            <th class="num">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($steps as $i => $step) {
                        $width = $step['of_first'] === null ? 0 : (int)round(100 * min(1.0, $step['of_first'])); ?>
                        <tr data-funnel-goal="<?php echo (int)$step['goal_id']; ?>">
                            <td>
                                <span class="d-block"><?php echo $e(($i + 1) . '. ' . $step['name']); ?><?php echo $step['builtin'] !== null ? ' <span class="p202-pill">built in</span>' : ''; ?></span>
                                <span class="progress mt-1" role="progressbar" aria-label="<?php echo $e($step['name'] . ': share of the first step'); ?>" aria-valuenow="<?php echo $width; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <span class="progress-bar" style="width: <?php echo $width; ?>%"></span>
                                </span>
                            </td>
                            <td class="num"><?php echo $num($step['installs']); ?></td>
                            <td class="num"><?php echo $e($pct($step['of_first'])); ?></td>
                            <td class="num"><?php echo $e($pct($step['of_previous'])); ?></td>
                            <td class="num"><?php echo $num($step['unvouched']); ?></td>
                            <td class="num"><?php echo $e($money($step['revenue'])); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="text-secondary small mb-0">An install counts at a step once, however often it reached it. Unvouched installs — organic among them — are counted beside, not in, the funnel: anyone holding the app token can report one. Revenue is what the steps paid on their campaigns.</p>
        </div>
    </section>
<?php } ?>
