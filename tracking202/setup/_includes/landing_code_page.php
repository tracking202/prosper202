<?php

declare(strict_types=1);

/**
 * Setup › Get LP Code, on the v2 shell: the page for a simple landing page
 * (one campaign) and the page for an advanced one (several offers).
 *
 * Both post their form to the AJAX endpoint that always generated the code
 * (tracking202/ajax/get_landing_code.php, get_adv_landing_code.php) and show
 * the answer in place — the names they post are the names those endpoints
 * read. get_dynamic_smart_component_code.php renders the simple page under
 * its own title; its classic form never loaded a campaign, so it never
 * produced any code.
 *
 * @param 'simple'|'advanced' $mode
 */
function p202_setup_landing_code_page(mysqli $db, string $mode, string $title, string $heading): void
{
    $base = get_absolute_url();
    $uid = (int) $_SESSION['user_id'];
    $token = (string) ($_SESSION['token'] ?? '');
    $simple = $mode === 'simple';

    $campaignOptions = p202_setup_campaign_options($db, $uid);
    $simplePageOptions = [];
    foreach (p202_setup_rows($db, "SELECT lp.landing_page_id, lp.landing_page_nickname, lp.aff_campaign_id FROM 202_landing_pages AS lp INNER JOIN 202_aff_campaigns AS ac ON (ac.aff_campaign_id = lp.aff_campaign_id) WHERE lp.user_id = '" . $uid . "' AND lp.landing_page_type = '0' AND lp.landing_page_deleted = '0' AND ac.aff_campaign_deleted = '0' ORDER BY lp.landing_page_nickname ASC") as $page) {
        $simplePageOptions[(string) $page['landing_page_id']] = ['label' => (string) $page['landing_page_nickname'], 'data' => ['campaign' => (string) $page['aff_campaign_id']]];
    }
    $advancedPageOptions = [];
    foreach (p202_setup_rows($db, "SELECT landing_page_id, landing_page_nickname FROM 202_landing_pages WHERE user_id = '" . $uid . "' AND landing_page_type = '1' AND landing_page_deleted = '0' ORDER BY landing_page_nickname ASC") as $page) {
        $advancedPageOptions[(string) $page['landing_page_id']] = (string) $page['landing_page_nickname'];
    }
    $rotatorOptions = [];
    foreach (p202_setup_rows($db, "SELECT id, name FROM 202_rotators WHERE user_id = '" . $uid . "' ORDER BY name ASC") as $rotator) {
        $rotatorOptions[(string) $rotator['id']] = (string) $rotator['name'];
    }
    $pages = $simple ? $simplePageOptions : $advancedPageOptions;

    /** One offer of an advanced page: numbered 1…n by p202-setup.js before each post. */
    $offerRow = static function (string $n, bool $removable) use ($campaignOptions, $rotatorOptions): string {
        return '<div class="p202-panel mb-3" data-p202-row data-lp-offer>'
            . '<div class="p202-panel__body">'
            . '<div class="d-flex flex-wrap gap-3 mb-2" role="radiogroup" aria-label="Offer type">'
            . '<div class="form-check mb-0"><input class="form-check-input" type="radio" name="offer_type' . $n . '" id="offer_type' . $n . '1" value="campaign" checked data-lp-offer-type><label class="form-check-label" for="offer_type' . $n . '1">Campaign</label></div>'
            . '<div class="form-check mb-0"><input class="form-check-input" type="radio" name="offer_type' . $n . '" id="offer_type' . $n . '2" value="rotator" data-lp-offer-type><label class="form-check-label" for="offer_type' . $n . '2">Redirector</label></div>'
            . ($removable ? '<button type="button" class="btn btn-link btn-sm text-danger ms-auto p-0" data-p202-remove-row>Remove this offer</button>' : '')
            . '</div>'
            . '<div data-lp-offer-pick="campaign"><label class="form-label" for="aff_campaign_id_' . $n . '">Campaign</label>'
            . '<select class="form-select" name="aff_campaign_id_' . $n . '" id="aff_campaign_id_' . $n . '">' . p202_setup_options($campaignOptions, null, 'Choose a campaign', '0') . '</select></div>'
            . '<div data-lp-offer-pick="rotator" hidden><label class="form-label" for="rotator_id_' . $n . '">Redirector</label>'
            . '<select class="form-select" name="rotator_id_' . $n . '" id="rotator_id_' . $n . '">' . p202_setup_options($rotatorOptions, null, 'Choose a redirector', '0') . '</select></div>'
            . '</div></div>';
    };

    template_top($title);
    ?>

    <div class="p202-page-header p202-page-header--accent">
        <div class="p202-page-header__icon"><i class="bi bi-terminal"></i></div>
        <div class="p202-page-header__text">
            <h1 class="p202-page-header__title"><?php echo p202_setup_e($heading); ?></h1>
            <p class="p202-page-header__desc">The code that tracks a visitor through your landing page to the offer.</p>
        </div>
    </div>

    <nav class="nav p202-tabs" aria-label="Landing page type">
        <a class="nav-link<?php echo $simple ? ' active' : ''; ?>" href="<?php echo p202_setup_e($base . 'tracking202/setup/get_simple_landing_code.php'); ?>"<?php echo $simple ? ' aria-current="page"' : ''; ?>>Simple page</a>
        <a class="nav-link<?php echo $simple ? '' : ' active'; ?>" href="<?php echo p202_setup_e($base . 'tracking202/setup/get_adv_landing_code.php'); ?>"<?php echo $simple ? '' : ' aria-current="page"'; ?>>Advanced page</a>
    </nav>

    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <?php if ($pages === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-file-earmark p202-empty__icon"></i>
                    <strong class="p202-empty__title">No <?php echo $simple ? 'simple' : 'advanced'; ?> landing pages yet</strong>
                    <div>The code is made for one of your landing pages. Add the page first.</div>
                    <div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/setup/landing_pages.php'); ?>">Add a landing page</a></div>
                </div>
            <?php } else { ?>
            <section class="p202-panel">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Your landing page</h2>
                    <p class="p202-panel__sub"><?php echo $simple ? 'A page that promotes one campaign: ad, then page, then offer.' : 'A page with several offers, or a redirector, to link out to.'; ?></p>
                </div>
                <div class="p202-panel__body">
                    <form method="post" id="tracking_form" action="<?php echo p202_setup_e($base . ($simple ? 'tracking202/ajax/get_landing_code.php' : 'tracking202/ajax/get_adv_landing_code.php')); ?>" data-p202-ajax-target="#tracking-links"<?php echo $simple ? '' : ' data-lp-offers'; ?>>
                        <?php echo p202_setup_token_field($token); ?>
                        <?php if ($simple) { ?>
                            <div class="mb-3">
                                <label class="form-label" for="aff_campaign_id">Campaign</label>
                                <select class="form-select" id="aff_campaign_id" name="aff_campaign_id" required>
                                    <?php echo p202_setup_options($campaignOptions, p202_setup_only_option($campaignOptions), 'Choose a campaign'); ?>
                                </select>
                                <input type="hidden" name="aff_network_id" value="" data-p202-sync-from="#aff_campaign_id" data-p202-sync-attr="network">
                                <input type="hidden" name="method_of_promotion" value="landingpage">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="landing_page_id">Landing page</label>
                                <select class="form-select" id="landing_page_id" name="landing_page_id" required data-p202-filter-by="#aff_campaign_id" data-p202-filter-key="campaign" data-p202-filter-empty="No landing pages for this campaign" data-p202-filter-choose="Choose a landing page">
                                    <?php echo p202_setup_options($simplePageOptions, null, 'Choose a landing page'); ?>
                                </select>
                            </div>
                        <?php } else { ?>
                            <input type="hidden" name="counter" value="0" data-lp-counter>
                            <div class="mb-3">
                                <label class="form-label" for="landing_page_id">Landing page</label>
                                <select class="form-select" id="landing_page_id" name="landing_page_id" required>
                                    <?php echo p202_setup_options($advancedPageOptions, count($advancedPageOptions) === 1 ? (string) array_key_first($advancedPageOptions) : null, 'Choose a landing page', ''); ?>
                                </select>
                            </div>
                            <div class="form-label">Offers on the page</div>
                            <div id="lp-offers"><?php echo $offerRow('1', false); ?></div>
                            <template id="lp-offer-template"><?php echo $offerRow('N', true); ?></template>
                            <button type="button" class="btn btn-secondary btn-sm mb-3" data-p202-add-row="#lp-offer-template" data-p202-add-into="#lp-offers"><i class="bi bi-plus"></i> Add another offer</button>
                        <?php } ?>
                        <div class="p202-form-actions">
                            <button type="submit" class="btn btn-primary" id="<?php echo $simple ? 'generate-tracking-link-simple' : 'generate-tracking-link-adv'; ?>">Get the code</button>
                        </div>
                    </form>
                </div>
            </section>
            <?php } ?>
        </div>

        <div class="col-12 col-lg-7">
            <section class="p202-panel" aria-live="polite">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Your code</h2>
                </div>
                <div class="p202-panel__body" id="tracking-links">
                    <p class="text-body-secondary mb-0">Choose your landing page, then Get the code: it appears here, ready to copy.</p>
                </div>
            </section>
        </div>
    </div>

    <?php echo p202_setup_script_tag($base); ?>
    <?php template_bottom();
}
