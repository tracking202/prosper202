<?php
include_once(str_repeat("../", 1) . '202-config/connect.php');

AUTH::require_user();

$strProtocol = stripos((string) $_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';

// Get Started checklist progress. Each step is checked off once the user has
// done it, and the whole card is hidden once all three are complete (it's no
// longer relevant). Caching is disabled so the state reflects the user's
// actions immediately rather than up to the cache TTL later.
$gs_user_id = (int) ($_SESSION['user_own_id'] ?? $_SESSION['user_id'] ?? 0);
$gs_count = static function (string $sql): int {
    $row = memcache_mysql_fetch_assoc($sql, 0);
    return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
};
// Join each child count to its parent table and require the parent to be active:
// deleting a traffic source / category only soft-deletes the parent row, leaving
// orphaned account/campaign rows behind. Without these joins those orphans would
// still mark the step complete (or hide the whole card) for a user who no longer
// has a usable source/category, instead of prompting them to recreate it.
$gs_has_traffic  = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_ppc_accounts a JOIN 202_ppc_networks n ON a.ppc_network_id = n.ppc_network_id WHERE a.user_id=" . $gs_user_id . " AND a.ppc_account_deleted='0' AND n.ppc_network_deleted='0'") > 0;
$gs_has_campaign = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_aff_campaigns c JOIN 202_aff_networks n ON c.aff_network_id = n.aff_network_id WHERE c.user_id=" . $gs_user_id . " AND c.aff_campaign_deleted='0' AND n.aff_network_deleted='0'") > 0;
$gs_has_tracker  = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_trackers WHERE user_id=" . $gs_user_id) > 0;
$gs_all_done = $gs_has_traffic && $gs_has_campaign && $gs_has_tracker;

$base = get_absolute_url();
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$gs_steps = [
    ['done' => $gs_has_traffic, 'href' => $base . 'tracking202/setup/ppc_accounts.php', 'label' => 'Add a traffic source', 'tail' => 'where your clicks come from'],
    ['done' => $gs_has_campaign, 'href' => $base . 'tracking202/setup/aff_campaigns.php', 'label' => 'Create a campaign', 'tail' => 'the offer you promote'],
    ['done' => $gs_has_tracker, 'href' => $base . 'tracking202/setup/get_trackers.php', 'label' => 'Generate a tracking link', 'tail' => 'and put it in your traffic source'],
];
$gs_next = null;
foreach ($gs_steps as $gs_step) {
    if (!$gs_step['done']) {
        $gs_next = $gs_step;
        break;
    }
}

$apps = [
    ['ClickServer', 'Advanced conversion tracking and optimization.', $base . 'tracking202/', 'shield.svg', false],
    ['TV202', 'Exclusive marketing interviews and tutorials.', $base . '202-tv/', 'video.svg', false],
    ['Resources202', 'More applications to help you sell.', $base . '202-resources/', 'basket.svg', false],
];
$resources = [
    ['Blog', 'The latest updates.', 'http://blog.tracking202.com/', 'news.svg'],
    ['Twitter', 'Follow along.', 'https://twitter.tracking202.com/', 'news.svg'],
    ['Newsletter', 'Updates in your inbox.', 'http://newsletter.tracking202.com', 'news.svg'],
    ['Community support', 'Talk with other users, and get help.', 'http://support.tracking202.com/', 'support.svg'],
    ['Developers', 'Do cool things with the Tracking202 APIs.', 'http://developers.tracking202.com', 'settings.svg'],
    ['Meetup202', 'Marketing meetup groups around the world.', 'http://meetup.tracking202.com', 'shirt.svg'],
];

template_top('Prosper202 ClickServer', ['ui' => 'v2']);  ?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-house"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Home</h1>
        <p class="p202-page-header__desc">Your applications, the next step in setting up, and where to get help.</p>
    </div>
    <div class="p202-page-header__actions">
        <a class="btn btn-primary" href="<?php echo $e($base . 'tracking202/'); ?>">Open the ClickServer</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <?php if (!$gs_all_done) { ?>
            <section class="p202-panel mb-4" id="p202-getting-started" hidden>
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Get started</h2>
                    <span class="p202-panel__sub">three steps to your first tracking link</span>
                    <div class="p202-panel__aside"><button type="button" class="btn-close" id="p202-gs-dismiss" aria-label="Dismiss the getting started checklist"></button></div>
                </div>
                <div class="p202-panel__body">
                    <?php foreach ($gs_steps as $index => $gs_step) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="gs-step-<?php echo $index; ?>" disabled<?php echo $gs_step['done'] ? ' checked' : ''; ?>>
                            <label class="form-check-label<?php echo $gs_step['done'] ? ' text-decoration-line-through text-secondary' : ''; ?>" for="gs-step-<?php echo $index; ?>">
                                <?php if ($gs_step['done']) { ?>
                                    <?php echo $e($gs_step['label']); ?>
                                <?php } else { ?>
                                    <a href="<?php echo $e($gs_step['href']); ?>"><?php echo $e($gs_step['label']); ?></a>
                                <?php } ?>
                                <span class="text-secondary">· <?php echo $e($gs_step['tail']); ?></span>
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($gs_next !== null) { ?>
                        <div class="p202-form-actions">
                            <a class="btn btn-secondary btn-sm" href="<?php echo $e($gs_next['href']); ?>"><?php echo $e($gs_next['label']); ?></a>
                        </div>
                    <?php } ?>
                    <p class="form-text mb-0">Prefer hands-free? Ask Claude to <strong>“onboard Prosper202”</strong> and it will set this up for you.</p>
                </div>
            </section>
            <script>
            (function () {
                /* Dismissed per browser: a convenience, so localStorage, and a
                   browser that refuses storage simply shows the card. */
                var card = document.getElementById('p202-getting-started');
                if (!card) { return; }
                var dismissed = false;
                try { dismissed = localStorage.getItem('p202_getting_started_dismissed') === '1'; } catch (err) {}
                card.hidden = dismissed;
                var x = document.getElementById('p202-gs-dismiss');
                if (x) {
                    x.addEventListener('click', function () {
                        try { localStorage.setItem('p202_getting_started_dismissed', '1'); } catch (err) {}
                        card.hidden = true;
                    });
                }
            })();
            </script>
        <?php } ?>

        <?php if (isset($_SESSION['user_pref_ad_settings']) && $_SESSION['user_pref_ad_settings'] != 'hide_all') { ?>
            <section class="p202-panel" id="special-offers">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Special offers</h2>
                    <span class="p202-panel__sub"><a href="<?php echo $e($base . '202-account/account.php#profile'); ?>">hide these</a></span>
                </div>
                <div class="p202-panel__body">
                    <iframe class="w-100 border-0 d-block" height="420" title="Special offers" src="<?php echo $e(TRACKING202_ADS_URL . '/prosper202-home/?t202aid=' . ($_SESSION['user_cirrus_link'] ?? '')); ?>" scrolling="no"></iframe>
                </div>
            </section>
        <?php } ?>
    </div>

    <div class="col-12 col-lg-5">
        <section class="p202-panel mb-4" id="applications">
            <div class="p202-panel__head"><h2 class="p202-panel__title">My applications</h2></div>
            <div class="p202-panel__body">
                <div class="list-group">
                    <?php foreach ($apps as [$name, $what, $href, $icon]) { ?>
                        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?php echo $e($href); ?>">
                            <img src="<?php echo $e($base . '202-img/new/icons/' . $icon); ?>" width="36" height="36" alt="">
                            <span><strong class="d-block"><?php echo $e($name); ?></strong><span class="small text-secondary"><?php echo $e($what); ?></span></span>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </section>

        <section class="p202-panel" id="extra-resources">
            <div class="p202-panel__head"><h2 class="p202-panel__title">Extra resources</h2><span class="p202-panel__sub">open in a new tab</span></div>
            <div class="p202-panel__body">
                <div class="list-group">
                    <?php foreach ($resources as [$name, $what, $href]) { ?>
                        <a class="list-group-item list-group-item-action" href="<?php echo $e($href); ?>" target="_blank" rel="noopener">
                            <strong class="d-block"><?php echo $e($name); ?></strong><span class="small text-secondary"><?php echo $e($what); ?></span>
                        </a>
                    <?php } ?>
                </div>
            </div>
        </section>
    </div>
</div>
<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo base64_encode($strProtocol . getTrackingDomain() . get_absolute_url()); ?>" width="1" height="1" alt="" class="position-absolute">

<?php template_bottom(); ?>
