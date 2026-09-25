<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps (plan §5.6, PR 11): register iOS and Android apps and
 * set each one up.
 *
 * On the v2 shell: Bootstrap 5.3 with the Prosper202 theme and the component
 * layer from 202-css/p202-components.css, every component copied from
 * 202-account/ui-kit.php with its parts (error pattern #19). No Bootstrap 3
 * or Flat UI class appears here or in the partials under mobile_apps/ —
 * tests/Api/V3/NoLegacyBootstrapClassesTest.php scans them and fails the
 * build if one does.
 *
 * The app page is one panel per job, in the order a new app needs them:
 * for iOS the conversion values first (nothing decodes without them); for
 * Android the store link first (nothing is attributed without it). Every
 * rarely-changed setting sits under a closed `Advanced` disclosure.
 *
 * @var array<string, mixed> $mobileApps  built by MobileAppsController::render()
 */

require_once dirname(__DIR__) . '/_includes/setup_ui.php';

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** Apple writes it iOS, so the page does too; strtoupper() shouted IOS. */
$platformLabel = static fn (mixed $p): string => ['ios' => 'iOS', 'android' => 'Android'][strtolower((string)$p)] ?? (string)$p;
/** What an app key is called on its platform. */
$keyLabel = static fn (mixed $p): string => strtolower((string)$p) === 'android' ? 'Package' : 'App Store ID';
$base = (string)$mobileApps['baseUrl'];
$self = $base . 'tracking202/setup/mobile_apps.php';
$canManage = (bool)$mobileApps['canManage'];
$fieldErrors = $mobileApps['fieldErrors'];
$form = $mobileApps['form'];
$app = $mobileApps['app'];
$icons = $mobileApps['icons'];
// scheme://host + app path. get_absolute_url() returns only the path, so the
// receiver URLs, the Info.plist keys and the SDK snippet all showed an empty
// origin without this. Escaped on output like everything else here: the Host
// header is attacker-controlled.
$origin = rtrim((string)$mobileApps['origin'], '/');

// Revenue is money, so it is rendered through the app's own formatter with
// the account's currency rather than a bare number_format — this install is
// not necessarily a dollar one. The symbol for the input's addon is read back
// out of a formatted zero instead of being a second copy of dollar_format()'s
// twenty-one-currency table, which could drift from it; and because three of
// those currencies put the symbol after the amount, where the addon goes is
// read from the formatter too rather than assumed to be the left.
$currency = (string)$mobileApps['currency'];
$money = static fn (mixed $v): string => (string)dollar_format((float)$v, $currency);
$formattedZero = (string)dollar_format(0, $currency);
$currencySymbol = str_replace(number_format(0, 2), '', $formattedZero);
$symbolLeads = $currencySymbol !== '' && str_starts_with($formattedZero, $currencySymbol);

/** The API's own sentence for a field, rendered where the field is. */
$fieldError = static function (string $field) use ($fieldErrors, $e): string {
    return isset($fieldErrors[$field])
        ? '<div class="invalid-feedback d-block">' . $e($fieldErrors[$field]) . '</div>'
        : '';
};
$invalid = static fn (string $field): string => isset($fieldErrors[$field]) ? ' is-invalid' : '';

/**
 * An app's mark: its store icon when the store gave one (a data: URI kept at
 * registration, so the viewer's browser calls no store), else the platform's.
 */
$appMark = static function (array $row) use ($icons, $e): string {
    $icon = $icons[(int)$row['registration_id']] ?? null;
    if ($icon !== null) {
        return '<img class="rounded align-text-bottom me-1" src="' . $e($icon) . '" alt="" width="20" height="20">';
    }
    return '<i class="bi ' . ((string)($row['platform'] ?? '') === 'android' ? 'bi-android2' : 'bi-apple') . ' me-1 text-body-secondary" aria-hidden="true"></i>';
};

/**
 * The "Your apps" pill and the "Getting started" checklist count one list, so
 * they read it from one place. Both wordings carry the truncation: the pill
 * says "500 of 620 apps", the checklist "500 of 620". Kept on the controller
 * where it is unit-tested, like the analyze page's own derivations.
 */
$appCount = \Tracking202\Setup\MobileAppsController::appCountLabels(
    count($mobileApps['apps']),
    $mobileApps['appsTotal'],
    $mobileApps['appsTruncated']
);

template_top('Mobile Apps - Setup', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
    <div class="p202-page-header__icon"><i class="bi bi-phone"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Mobile App Attribution</h1>
        <p class="p202-page-header__desc">Register the iOS and Android apps you advertise, give their campaigns the right store link, and choose the goals each app reports.</p>
    </div>
</div>

<?php
foreach ($mobileApps['flashes'] as $flash) {
    echo p202_flash($flash['kind'], $flash['text']);
}
if (!$canManage) {
    echo p202_flash('warn', 'You can see the registered apps here. Changing them needs the attribution models permission.');
}
?>

<?php if ($app === null) { ?>

    <!-- ── Receivers ─────────────────────────────────────────────── -->
    <section class="p202-section" id="receivers">
        <div class="p202-strip" data-receiver-strip data-origin="<?php echo $e($origin); ?>">
            <?php
            $receivers = [
                ['label' => 'SKAdNetwork receiver (iOS)', 'path' => '/.well-known/skadnetwork/report-attribution/'],
                ['label' => 'AdAttributionKit receiver (iOS)', 'path' => '/.well-known/appattribution/report-attribution/'],
            ];
            foreach ($receivers as $receiver) { ?>
                <div class="p202-strip__row">
                    <span class="p202-pill" data-receiver-pill data-url="<?php echo $e($origin . $receiver['path']); ?>">Checking…</span>
                    <span class="p202-strip__label"><?php echo $e($receiver['label']); ?></span>
                    <span class="p202-strip__value"><code><?php echo $e($origin . $receiver['path']); ?></code></span>
                    <span class="p202-strip__aside">
                        <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($origin . $receiver['path']); ?>">Copy</button>
                    </span>
                </div>
            <?php } ?>
            <p class="p202-strip__note">
                Give Apple the bare origin <code><?php echo $e($origin); ?></code> in <code>Info.plist</code>; Apple appends the paths itself. Android apps report to this server through the SDK instead: each Android app's page checks that.
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-receiver-recheck>Re-check</button>
            </p>
        </div>
    </section>

    <div class="row g-4">
        <!-- ── Register ──────────────────────────────────────────── -->
        <div class="col-12 col-lg-7">
            <?php if ($canManage) { ?>
            <section class="p202-panel" id="register">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title"><?php echo $mobileApps['editing'] ? 'Edit app' : 'Register an app'; ?></h2>
                    <p class="p202-panel__sub"><?php echo $mobileApps['editing'] ? 'Which app it is cannot change; register a second app instead.' : 'One field. The platform, name and icon are picked up for you.'; ?></p>
                </div>
                <div class="p202-panel__body">
                    <?php if ($mobileApps['editing']) {
                        $settingsApp = $mobileApps['editing'];
                        $settingsReturn = 'list';
                        require __DIR__ . '/mobile_apps/_settings.php';
                    } else { ?>
                        <form method="post" action="<?php echo $e($self); ?>">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="register">
                            <div class="mb-3">
                                <label class="form-label" for="app_reference">App Store or Google Play link</label>
                                <input class="form-control<?php echo $invalid('app_reference'); ?>" type="text" id="app_reference" name="app_reference"
                                       value="<?php echo $e($form['app_reference'] ?? ''); ?>"
                                       placeholder="https://play.google.com/store/apps/details?id=com.example.summit" required autofocus>
                                <div class="form-text">Paste the store link. An App Store id or a package name works too.</div>
                                <?php echo $fieldError('app_reference'); ?>
                            </div>

                            <?php if (!empty($form['needs_name'])) { ?>
                                <div class="p202-decided mb-3"><i class="bi bi-check2-circle"></i>
                                    Read as <?php echo $e($platformLabel($form['derived_platform'])); ?> · <?php echo $e($keyLabel($form['derived_platform'])); ?> <?php echo $e($form['derived_app_key']); ?>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="app_name">App name</label>
                                    <input class="form-control<?php echo $invalid('app_name'); ?>" type="text" id="app_name" name="app_name" value="<?php echo $e($form['app_name'] ?? ''); ?>" maxlength="255" required autofocus>
                                    <?php echo $fieldError('app_name'); ?>
                                </div>
                            <?php } ?>

                            <details class="p202-disclosure" data-p202-remember="setup-mobile-apps-advanced" <?php echo ($form['notes'] ?? '') !== '' || isset($form['accept_test_signals']) ? 'open' : ''; ?>>
                                <summary>Advanced <span class="p202-disclosure__hint">notes, test signals</span></summary>
                                <div class="p202-disclosure__body">
                                    <div class="mb-3">
                                        <label class="form-label" for="notes">Notes</label>
                                        <input class="form-control" type="text" id="notes" name="notes" value="<?php echo $e($form['notes'] ?? ''); ?>" maxlength="500">
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="accept_test_signals" name="accept_test_signals" value="1" <?php echo isset($form['accept_test_signals']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="accept_test_signals">
                                            Count test signals while testing
                                            <span class="d-block form-text">Off by default. iOS: development-signed postbacks, which prove only that some device was in Developer Mode. Android: installs a debug build marks as tests.</span>
                                        </label>
                                    </div>
                                </div>
                            </details>

                            <div class="p202-form-actions">
                                <button class="btn btn-primary" type="submit">Register app</button>
                            </div>
                        </form>
                    <?php } ?>
                </div>
            </section>
            <?php } ?>

            <?php if ($mobileApps['apps'] === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-phone p202-empty__icon"></i>
                    <strong class="p202-empty__title">No apps registered yet</strong>
                    <div>Paste the store link of the app you advertise above. iOS postbacks that arrive before you register are kept for 30 days and claimed when you do.</div>
                </div>
            <?php } else { ?>
                <section class="p202-panel">
                    <div class="p202-panel__head">
                        <h2 class="p202-panel__title">Getting started</h2>
                    </div>
                    <div class="p202-panel__body">
                        <ol class="p202-list">
                            <li class="p202-list__item"><span class="p202-list__name">Register the app you advertise</span><span class="p202-list__meta"><?php echo $e($appCount['checklist']); ?></span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Point its campaigns at the store link the app's page builds</span><span class="p202-list__meta">Link builder, on the app page</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Choose the goals the app reports, and for iOS the conversion values that decode them</span><span class="p202-list__meta">on the app page</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Build the app with the SDK and its app token</span><span class="p202-list__meta">on the app page</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">iOS: both receivers answer over HTTPS</span><span class="p202-list__meta" data-receiver-summary role="status" aria-live="polite">checking…</span></li>
                        </ol>
                    </div>
                </section>
            <?php } ?>
        </div>

        <!-- ── Your apps ─────────────────────────────────────────── -->
        <div class="col-12 col-lg-5">
            <section class="p202-panel">
                <div class="p202-panel__head">
                    <h2 class="p202-panel__title">Your apps</h2>
                    <span class="p202-pill p202-pill--accent"><?php echo $e($appCount['pill']); ?></span>
                </div>
                <div class="p202-panel__body">
                    <?php if ($mobileApps['apps'] === []) { ?>
                        <p class="text-body-secondary mb-0">Nothing registered yet.</p>
                    <?php } else { ?>
                        <ul class="p202-list">
                            <?php foreach ($mobileApps['apps'] as $row) {
                                $rowId = (int)$row['registration_id'];
                                $nudge = $mobileApps['nudges'][$rowId] ?? 0; ?>
                                <li class="p202-list__item">
                                    <span class="p202-list__name">
                                        <?php echo $appMark($row); ?>
                                        <a href="<?php echo $e($self . '?app=' . $rowId); ?>"><?php echo $e($row['app_name']); ?></a>
                                    </span>
                                    <span class="p202-list__meta">
                                        <?php echo $e($platformLabel($row['platform'] ?? 'ios')); ?> · <?php echo $e($row['app_key']); ?>
                                        <?php if ((int)($row['accept_test_signals'] ?? 0) === 1) { ?>
                                            <span class="p202-pill p202-pill--warn">test signals on</span>
                                        <?php } ?>
                                    </span>
                                    <span class="p202-list__actions">
                                        <a class="p202-list__action" href="<?php echo $e($self . '?app=' . $rowId); ?>">open</a>
                                        <?php if ($canManage) { ?>
                                            <a class="p202-list__action" href="<?php echo $e($self . '?edit=' . $rowId); ?>">edit</a>
                                            <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Remove this registration? iOS postbacks it already claimed keep their owner, and Android installs keep their conversions; its goals are archived and test trust is withdrawn.">
                                                <?php echo $mobileApps['csrf']; ?>
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                                                <button class="p202-list__action p202-list__action--danger" type="submit">remove</button>
                                            </form>
                                        <?php } ?>
                                    </span>
                                    <?php if ($nudge > 0 && $canManage) { ?>
                                        <span class="p202-list__children">
                                            <form method="post" action="<?php echo $e($self); ?>" class="alert alert-warning p202-flash mb-0">
                                                <?php echo $mobileApps['csrf']; ?>
                                                <input type="hidden" name="action" value="accept_dev">
                                                <input type="hidden" name="registration_id" value="<?php echo $rowId; ?>">
                                                <input type="hidden" name="accept" value="1">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <div class="p202-flash__body">
                                                    <div><?php echo $nudge; ?> development <?php echo $nudge === 1 ? 'postback has' : 'postbacks have'; ?> arrived for this app and <?php echo $nudge === 1 ? 'is' : 'are'; ?> not counted. Accept them while you test?</div>
                                                    <button class="btn btn-sm btn-warning mt-2" type="submit">Accept</button>
                                                </div>
                                            </form>
                                        </span>
                                    <?php } ?>
                                </li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </div>
            </section>
        </div>
    </div>

<?php } else {
    // ── App detail ──────────────────────────────────────────────────
    $rowId = (int)$app['registration_id'];
    $isIos = (string)($app['platform'] ?? 'ios') === 'ios';
    $token = (string)($app['app_token'] ?? '');
    $masked = $token === '' ? '' : mb_substr($token, 0, 4) . str_repeat('•', max(0, mb_strlen($token) - 8)) . mb_substr($token, -4);
    $analyzeUrl = $base . 'tracking202/analyze/mobile_apps.php?registration_id=' . $rowId . '&platform=' . ($isIos ? 'ios' : 'android');
    ?>
    <div class="p202-decided mb-3">
        <a href="<?php echo $e($self); ?>">Your apps</a> › <?php echo $appMark($app); ?> <?php echo $e($app['app_name']); ?> ·
        <?php echo $e($platformLabel($app['platform'] ?? 'ios')); ?> · <?php echo $e($keyLabel($app['platform'] ?? 'ios')); ?> <?php echo $e($app['app_key']); ?>
        <?php if ($canManage) { ?> · <a href="#settings">change</a><?php } ?>
        · <a href="<?php echo $e($analyzeUrl); ?>">report</a>
    </div>

    <?php
    if ($isIos) {
        require __DIR__ . '/mobile_apps/_ios_values.php';
        require __DIR__ . '/mobile_apps/_goals.php';
        require __DIR__ . '/mobile_apps/_link_builder.php';
        require __DIR__ . '/mobile_apps/_ios_sdk.php';
    } else {
        require __DIR__ . '/mobile_apps/_link_builder.php';
        require __DIR__ . '/mobile_apps/_android_sdk.php';
        require __DIR__ . '/mobile_apps/_goals.php';
        require __DIR__ . '/mobile_apps/_integrity.php';
    }
    ?>

    <section class="p202-panel" id="settings">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Settings</h2>
            <p class="p202-panel__sub">The name reports show, and how this app's signals are counted.</p>
        </div>
        <div class="p202-panel__body">
            <?php if ($canManage) {
                $settingsApp = $app;
                $settingsReturn = 'app';
                require __DIR__ . '/mobile_apps/_settings.php';
            } else { ?>
                <p class="text-body-secondary mb-0">Changing these needs the attribution models permission.</p>
            <?php } ?>
        </div>
    </section>
<?php } ?>

<?php echo p202_setup_script_tag($base); ?>
<script>
/* The receiver checks (iOS on the list, the install intake on an Android
   app), the fine/coarse swap, and the goal menu; copy, reveal, confirm and
   the disclosures come from p202-ui.js, show-when from p202-setup.js. */
(function () {
    'use strict';

    function checkReceivers() {
        var pills = document.querySelectorAll('[data-receiver-pill]');
        var summary = document.querySelector('[data-receiver-summary]');
        var ready = 0;
        var done = 0;

        /* Called from every path that finishes a pill, the synchronous one
           included. Leaving it only in the fetch handlers left the checklist
           reading "checking…" for good on an install where every receiver
           took the branch below and no promise ever settled. */
        function settle() {
            done++;
            if (summary && done === pills.length) {
                summary.textContent = ready === pills.length
                    ? (pills.length === 2 ? 'both ready' : 'all ready')
                    : ready + ' of ' + pills.length + ' ready';
            }
        }

        if (summary) {
            summary.textContent = 'checking…';
        }
        Array.prototype.forEach.call(pills, function (pill) {
            var url = pill.getAttribute('data-url') || '';
            var token = pill.getAttribute('data-app-token');
            pill.textContent = 'Checking…';
            pill.className = 'p202-pill';
            /* Apple calls the receiver over public HTTPS on port 443 and
               nothing else, and an Android device refuses cleartext by
               default, so neither another scheme nor another port can work
               however the fetch below turns out. Saying "Ready" because the
               browser reached it would be a green light for a URL the
               platform will never call. The port is part of the rule, so it
               is part of the test. */
            var reachable = false;
            try {
                /* No base: data-url is always absolute (origin + path), so
                   resolving a blank or malformed one against this page would
                   turn it into the page's own URL and read Ready. Without a
                   base it throws, and the catch refuses it. */
                var parsed = new URL(url);
                reachable = parsed.protocol === 'https:'
                    && (parsed.port === '' || parsed.port === '443');
            } catch (error) {
                reachable = false;
            }
            if (!reachable) {
                pill.textContent = token ? 'Devices cannot reach this' : 'Apple cannot reach this';
                pill.className = 'p202-pill p202-pill--warn';
                pill.title = (token ? 'Android devices refuse cleartext and the SDK calls HTTPS on port 443.' : 'Apple only calls this endpoint over HTTPS on port 443.')
                    + ' This install advertises ' + url + '. Reachable from here, but not from a device.';
                settle();
                return;
            }
            var init = { method: 'GET', credentials: 'omit' };
            if (token) {
                init.headers = { 'X-P202-App-Token': token };
            }
            fetch(url, init).then(function (response) {
                if (response.ok) {
                    pill.textContent = 'Ready';
                    pill.className = 'p202-pill p202-pill--good';
                    ready++;
                } else {
                    pill.textContent = 'HTTP ' + response.status;
                    pill.className = 'p202-pill p202-pill--bad';
                }
            }).catch(function () {
                pill.textContent = 'Not reachable';
                pill.className = 'p202-pill p202-pill--bad';
                pill.title = 'Devices need this URL on public HTTPS, port 443.';
            }).then(settle);
        });
    }

    function swapKind() {
        var fine = document.querySelector('[data-kind-fine]');
        var coarse = document.querySelector('[data-kind-coarse]');
        var chosen = document.querySelector('input[name="kind"]:checked');
        if (!fine || !coarse || !chosen) {
            return;
        }
        fine.hidden = chosen.value !== 'fine';
        coarse.hidden = chosen.value !== 'coarse';
    }

    function init() {
        if (document.querySelector('[data-receiver-pill]')) {
            checkReceivers();
        }
        var recheck = document.querySelector('[data-receiver-recheck]');
        if (recheck) {
            recheck.addEventListener('click', checkReceivers);
        }
        Array.prototype.forEach.call(document.querySelectorAll('input[name="kind"]'), function (radio) {
            radio.addEventListener('change', swapKind);
        });
        swapKind();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php template_bottom(); ?>
