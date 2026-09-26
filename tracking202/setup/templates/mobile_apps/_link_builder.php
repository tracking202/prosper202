<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps: the link builder (plan §5.6, PR 11) — the store link
 * a campaign advertising this app sends its clicks to, and one click to
 * make a campaign do so.
 *
 *  - Android: the Play link with [[p202_install_token]] in its referrer,
 *    and the campaign linked to the app, so an install on one of its clicks
 *    is tied to that click and an install of another app is refused.
 *  - iOS: the App Store link. Nothing rides in it — SKAdNetwork and
 *    AdAttributionKit attribute the install to the ad network's signed
 *    impression — so the campaign is not linked to the app; the setup that
 *    matters is the Info.plist keys (App token & SDK, below).
 *
 * The read is GET /apps/{id}/store-link (AppLinksController), the write
 * PUT /campaigns/{id} with what that read says to apply — the same round
 * trip as `p202 app link <id> --campaign-id N --apply`.
 *
 * @var array<string, mixed> $mobileApps
 * @var array<string, mixed> $app
 * @var int $rowId
 * @var bool $isIos
 * @var bool $canManage
 * @var string $self
 * @var string $base
 * @var callable $e
 * @var callable $fieldError
 * @var callable $invalid
 */

$lb = $mobileApps['link'];
$store = $lb['store'];
$lbCampaign = $lb['campaign'];
$campaignOptions = [];
foreach ((array)($lb['campaigns'] ?? []) as $c) {
    $linkedElsewhere = !$isIos && ($c['app_registration_id'] ?? null) !== null && (int)$c['app_registration_id'] !== $rowId;
    $campaignOptions[(string)$c['aff_campaign_id']] = (string)$c['aff_campaign_name']
        . ((int)($c['app_registration_id'] ?? 0) === $rowId && !$isIos ? ' (linked)' : ($linkedElsewhere ? ' (linked to another app)' : ''));
}
?>
<section class="p202-panel" id="link-builder">
    <div class="p202-panel__head">
        <h2 class="p202-panel__title">Store link</h2>
        <p class="p202-panel__sub"><?php echo $isIos
            ? 'Where a campaign advertising this app sends its clicks. Apple attributes the install to the ad network, not the click, so the link carries nothing more.'
            : 'Where a campaign advertising this app sends its clicks. The redirect fills in the install token per click, and the SDK reads it back from Google Play, which is what ties an install to its click.'; ?></p>
    </div>
    <div class="p202-panel__body">
        <?php if ($store === null) { ?>
            <div class="alert alert-warning p202-flash" role="status">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="p202-flash__body">The store link could not be built just now. Reload the page; if it keeps happening the server log has the reason.</div>
            </div>
        <?php } else {
            echo p202_setup_code_box((string)$store['store_link'], ['label' => $isIos ? 'App Store link' : 'Google Play link, with the install token', 'id' => 'store-link']);
        } ?>

        <?php if ($lb['campaigns'] === null) { ?>
            <p class="text-secondary small mb-0">Your campaigns could not be read just now, so none can be pointed at this link from here. <code>p202 app link <?php echo $rowId; ?> --campaign-id N --apply</code> does the same.</p>
        <?php } elseif ($lb['campaigns'] === []) { ?>
            <div class="p202-empty">
                <i class="bi bi-megaphone p202-empty__icon"></i>
                <strong class="p202-empty__title">No campaign to point at it yet</strong>
                <div>Add the campaign that advertises this app with the link above as its offer URL<?php echo $isIos ? '' : ', then come back to link it to the app'; ?>.</div>
                <div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e($base . 'tracking202/setup/aff_campaigns.php'); ?>">Add a campaign</a></div>
            </div>
        <?php } else { ?>
            <form method="get" action="<?php echo $e($self); ?>" class="p202-toolbar mb-3">
                <input type="hidden" name="app" value="<?php echo $rowId; ?>">
                <label class="form-label mb-0" for="link_campaign">Campaign</label>
                <select class="form-select form-select-sm w-auto<?php echo $invalid('campaign_id'); ?>" id="link_campaign" name="link_campaign">
                    <?php echo p202_setup_options($campaignOptions, $lb['chosen'], 'Choose the campaign that advertises this app'); ?>
                </select>
                <button class="btn btn-secondary btn-sm" type="submit">Check</button>
            </form>
            <?php echo $fieldError('campaign_id'); ?>

            <?php if ($lbCampaign !== null) { ?>
                <div class="p202-strip mb-3" id="link-state">
                    <div class="p202-strip__row">
                        <span class="p202-pill <?php echo $lbCampaign['ready'] ? 'p202-pill--good' : 'p202-pill--warn'; ?>"><?php echo $lbCampaign['ready'] ? 'Ready' : 'Not yet'; ?></span>
                        <span class="p202-strip__label"><?php echo $e($lbCampaign['aff_campaign_name']); ?></span>
                        <span class="p202-strip__value"><code><?php echo $e($lbCampaign['aff_campaign_url']); ?></code></span>
                    </div>
                    <?php foreach ((array)$lbCampaign['needs'] as $need) { ?>
                        <div class="p202-strip__row">
                            <span class="p202-pill">to do</span>
                            <span class="p202-strip__value"><?php echo $e(preg_replace('/^[a-z_]+: /', '', (string)$need)); ?></span>
                        </div>
                    <?php } ?>
                </div>
                <?php if (!$lbCampaign['ready'] && $canManage) { ?>
                    <form method="post" action="<?php echo $e($self); ?>" data-p202-confirm="<?php echo $e('Change the campaign "' . $lbCampaign['aff_campaign_name'] . '"? Its offer URL becomes this app\'s store link' . ($isIos ? '.' : ' and it is linked to this app, so an install of another app on its clicks is refused.') . ' Clicks already tracked keep the URL they were sent to.'); ?>">
                        <?php echo $mobileApps['csrf']; ?>
                        <input type="hidden" name="action" value="link_apply">
                        <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                        <input type="hidden" name="campaign_id" value="<?php echo (int)$lbCampaign['aff_campaign_id']; ?>">
                        <div class="p202-form-actions justify-content-start">
                            <button class="btn btn-primary" type="submit">Use this link on the campaign</button>
                        </div>
                    </form>
                <?php } elseif ($lbCampaign['ready']) { ?>
                    <p class="mb-0">Next: <a href="<?php echo $e($base . 'tracking202/setup/get_trackers.php'); ?>">get the campaign's tracking link</a> and give it to the traffic source.</p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>
</section>
