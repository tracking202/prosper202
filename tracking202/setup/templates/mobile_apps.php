<?php

declare(strict_types=1);

/**
 * Setup › Mobile Apps.
 *
 * The first page on the v2 shell: Bootstrap 5.3 with the Prosper202 theme and
 * the component layer from 202-css/p202-components.css. No Bootstrap 3 or
 * Flat UI class appears here — tests/Api/V3/NoLegacyBootstrapClassesTest.php
 * scans every file that passes 'ui' => 'v2' and fails the build if one does.
 *
 * @var array<string, mixed> $mobileApps  built by MobileAppsController::render()
 */

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
/** Apple writes it iOS, so the page does too; strtoupper() shouted IOS. */
$platformLabel = static fn (mixed $p): string => ['ios' => 'iOS', 'android' => 'Android'][strtolower((string)$p)] ?? (string)$p;
$base = (string)$mobileApps['baseUrl'];
$self = $base . 'tracking202/setup/mobile_apps.php';
$canManage = (bool)$mobileApps['canManage'];
$fieldErrors = $mobileApps['fieldErrors'];
$form = $mobileApps['form'];
$app = $mobileApps['app'];
// scheme://host + app path. get_absolute_url() returns only the path, so the
// receiver URLs, the Info.plist keys and the SDK snippet all showed an empty
// origin without this. Escaped on output like everything else here: the Host
// header is attacker-controlled.
$origin = rtrim((string)$mobileApps['origin'], '/');

/** The API's own sentence for a field, rendered where the field is. */
$fieldError = static function (string $field) use ($fieldErrors, $e): string {
    return isset($fieldErrors[$field])
        ? '<div class="invalid-feedback d-block">' . $e($fieldErrors[$field]) . '</div>'
        : '';
};
$invalid = static fn (string $field): string => isset($fieldErrors[$field]) ? ' is-invalid' : '';

template_top('Mobile Apps - Setup', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
    <div class="p202-page-header__icon"><i class="bi bi-phone"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Mobile App Attribution</h1>
        <p class="p202-page-header__desc">Register the apps you advertise, decode their SKAdNetwork and AdAttributionKit conversion values, and connect the Swift SDK.</p>
    </div>
</div>

<?php
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
foreach ($mobileApps['flashes'] as $flash) {
    echo $flashMarkup($flash['kind'], $flash['text']);
}
if (!$canManage) {
    echo $flashMarkup('warn', 'You can see the registered apps here. Changing them needs the attribution models permission.');
}
?>

<?php if ($app === null) { ?>

    <!-- ── Receivers ─────────────────────────────────────────────── -->
    <section class="p202-section" id="receivers">
        <div class="p202-strip" data-receiver-strip data-origin="<?php echo $e($origin); ?>">
            <?php
            $receivers = [
                ['label' => 'SKAdNetwork receiver', 'path' => '/.well-known/skadnetwork/report-attribution/'],
                ['label' => 'AdAttributionKit receiver', 'path' => '/.well-known/appattribution/report-attribution/'],
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
                Give Apple the bare origin <code><?php echo $e($origin); ?></code> in <code>Info.plist</code>; Apple appends the paths itself.
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
                    <p class="p202-panel__sub"><?php echo $mobileApps['editing'] ? 'The App Store id cannot change; register a second app instead.' : 'One field. The name and platform are picked up for you.'; ?></p>
                </div>
                <div class="p202-panel__body">
                    <?php if ($mobileApps['editing']) {
                        $editing = $mobileApps['editing']; ?>
                        <form method="post" action="<?php echo $e($self); ?>">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="attribution_app_id" value="<?php echo (int)$editing['attribution_app_id']; ?>">
                            <p class="p202-decided">
                                <?php echo $e($editing['app_name']); ?> · <?php echo $e($platformLabel($editing['platform'] ?? 'ios')); ?> · App Store ID <?php echo (int)$editing['app_id']; ?>
                            </p>
                            <div class="mb-3">
                                <label class="form-label" for="app_name">App name</label>
                                <input class="form-control<?php echo $invalid('app_name'); ?>" type="text" id="app_name" name="app_name" value="<?php echo $e($form['app_name'] ?? $editing['app_name']); ?>" maxlength="255" required>
                                <?php echo $fieldError('app_name'); ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="notes">Notes <span class="text-body-secondary">optional</span></label>
                                <input class="form-control<?php echo $invalid('notes'); ?>" type="text" id="notes" name="notes" value="<?php echo $e($form['notes'] ?? ($editing['notes'] ?? '')); ?>" maxlength="500">
                                <?php echo $fieldError('notes'); ?>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="accept_development_postbacks" name="accept_development_postbacks" value="1" <?php echo (int)($editing['accept_development_postbacks'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="accept_development_postbacks">
                                    Accept development postbacks
                                    <span class="d-block form-text">Counts postbacks signed with Apple's development key for this app. Anyone in Developer Mode can mint those, so leave it off outside testing.</span>
                                </label>
                            </div>
                            <div class="p202-form-actions">
                                <button class="btn btn-primary" type="submit">Save changes</button>
                                <a class="btn btn-link" href="<?php echo $e($self); ?>">Cancel</a>
                            </div>
                        </form>
                    <?php } else { ?>
                        <form method="post" action="<?php echo $e($self); ?>">
                            <?php echo $mobileApps['csrf']; ?>
                            <input type="hidden" name="action" value="register">
                            <div class="mb-3">
                                <label class="form-label" for="app_reference">App Store link or ID</label>
                                <input class="form-control<?php echo $invalid('app_reference'); ?>" type="text" id="app_reference" name="app_reference"
                                       value="<?php echo $e($form['app_reference'] ?? ''); ?>"
                                       placeholder="https://apps.apple.com/us/app/summit-run/id990077001" required autofocus>
                                <div class="form-text">Paste the App Store link. The name and platform are picked up for you.</div>
                                <?php echo $fieldError('app_reference'); ?>
                            </div>

                            <?php if (!empty($form['needs_name'])) { ?>
                                <p class="p202-decided">
                                    Read as App Store ID <?php echo (int)$form['derived_app_id']; ?> · <?php echo $e($platformLabel($form['derived_platform'])); ?>
                                </p>
                                <div class="mb-3">
                                    <label class="form-label" for="app_name">App name</label>
                                    <input class="form-control<?php echo $invalid('app_name'); ?>" type="text" id="app_name" name="app_name" value="<?php echo $e($form['app_name'] ?? ''); ?>" maxlength="255" required autofocus>
                                    <?php echo $fieldError('app_name'); ?>
                                </div>
                            <?php } ?>

                            <details class="p202-disclosure" data-p202-remember="setup-mobile-apps-advanced" <?php echo ($form['notes'] ?? '') !== '' || isset($form['accept_development_postbacks']) ? 'open' : ''; ?>>
                                <summary>Advanced <span class="p202-disclosure__hint">notes, development postbacks, platform</span></summary>
                                <div class="p202-disclosure__body">
                                    <div class="mb-3">
                                        <label class="form-label" for="notes">Notes</label>
                                        <input class="form-control" type="text" id="notes" name="notes" value="<?php echo $e($form['notes'] ?? ''); ?>" maxlength="500">
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="accept_development_postbacks" name="accept_development_postbacks" value="1" <?php echo isset($form['accept_development_postbacks']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="accept_development_postbacks">
                                            Accept development postbacks while testing
                                            <span class="d-block form-text">Off by default: a development signature proves only that some device was in Developer Mode, not that the install was real.</span>
                                        </label>
                                    </div>
                                    <fieldset>
                                        <legend class="form-label">Platform</legend>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="platform" id="platform_ios" value="ios" checked>
                                            <label class="form-check-label" for="platform_ios">iOS · App Store</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="platform" id="platform_android" value="android" disabled>
                                            <label class="form-check-label text-body-secondary" for="platform_android">Android · Google Play <span class="p202-pill">not supported yet</span></label>
                                        </div>
                                        <?php echo $fieldError('platform'); ?>
                                    </fieldset>
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
                    <div>Register the app you advertise so its postbacks are claimed and decoded. Postbacks that arrive before you register are kept for 30 days and claimed when you do.</div>
                </div>
            <?php } else { ?>
                <section class="p202-panel">
                    <div class="p202-panel__head">
                        <h2 class="p202-panel__title">Getting started</h2>
                    </div>
                    <div class="p202-panel__body">
                        <ol class="p202-list">
                            <li class="p202-list__item"><span class="p202-list__name">Both receivers answer over HTTPS</span><span class="p202-list__meta" data-receiver-summary>checking…</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Register the app you advertise</span><span class="p202-list__meta"><?php echo count($mobileApps['apps']); ?> registered</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Add conversion-value rules so postbacks decode to events and revenue</span><span class="p202-list__meta">open an app below</span></li>
                            <li class="p202-list__item"><span class="p202-list__name">Add the Info.plist keys and configure the SDK with the schema token</span><span class="p202-list__meta">on the app page</span></li>
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
                    <span class="p202-pill p202-pill--accent"><?php echo count($mobileApps['apps']); ?> <?php echo count($mobileApps['apps']) === 1 ? 'app' : 'apps'; ?></span>
                </div>
                <div class="p202-panel__body">
                    <?php if ($mobileApps['apps'] === []) { ?>
                        <p class="text-body-secondary mb-0">Nothing registered yet.</p>
                    <?php } else { ?>
                        <ul class="p202-list">
                            <?php foreach ($mobileApps['apps'] as $row) {
                                $rowId = (int)$row['attribution_app_id'];
                                $nudge = $mobileApps['nudges'][$rowId] ?? 0; ?>
                                <li class="p202-list__item">
                                    <span class="p202-list__name">
                                        <a href="<?php echo $e($self . '?app=' . $rowId); ?>"><?php echo $e($row['app_name']); ?></a>
                                    </span>
                                    <span class="p202-list__meta">
                                        <?php echo $e($platformLabel($row['platform'] ?? 'ios')); ?> · <?php echo (int)$row['app_id']; ?>
                                        <?php if ((int)($row['accept_development_postbacks'] ?? 0) === 1) { ?>
                                            <span class="p202-pill p202-pill--warn">dev postbacks on</span>
                                        <?php } ?>
                                    </span>
                                    <span class="p202-list__actions">
                                        <a class="p202-list__action" href="<?php echo $e($self . '?app=' . $rowId); ?>">open</a>
                                        <?php if ($canManage) { ?>
                                            <a class="p202-list__action" href="<?php echo $e($self . '?edit=' . $rowId); ?>">edit</a>
                                            <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Remove this registration? Postbacks it already claimed keep their owner. Development trust is withdrawn.">
                                                <?php echo $mobileApps['csrf']; ?>
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
                                                <button class="p202-list__action p202-list__action--danger" type="submit">remove</button>
                                            </form>
                                        <?php } ?>
                                    </span>
                                    <?php if ($nudge > 0 && $canManage) { ?>
                                        <span class="p202-list__children">
                                            <form method="post" action="<?php echo $e($self); ?>" class="alert alert-warning p202-flash mb-0">
                                                <?php echo $mobileApps['csrf']; ?>
                                                <input type="hidden" name="action" value="accept_dev">
                                                <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
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
    $rowId = (int)$app['attribution_app_id'];
    $appStoreId = (int)$app['app_id'];
    $token = (string)($app['schema_token'] ?? '');
    $masked = $token === '' ? '' : mb_substr($token, 0, 4) . str_repeat('•', max(0, mb_strlen($token) - 8)) . mb_substr($token, -4);
    $editRule = null;
    foreach ($mobileApps['rules'] as $candidate) {
        if ((int)($candidate['rule_id'] ?? 0) === (int)($_GET['rule_edit'] ?? 0)) {
            $editRule = $candidate;
        }
    }
    ?>
    <p class="p202-decided">
        <a href="<?php echo $e($self); ?>">Your apps</a> › <?php echo $e($app['app_name']); ?> ·
        <?php echo $e($platformLabel($app['platform'] ?? 'ios')); ?> · App Store ID <?php echo $appStoreId; ?>
        <?php if ($canManage) { ?> · <a href="<?php echo $e($self . '?edit=' . $rowId); ?>">change</a><?php } ?>
    </p>

    <section class="p202-panel">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Conversion values</h2>
            <p class="p202-panel__sub">Rules that decode a postback's value into an event and revenue.</p>
            <span class="p202-pill p202-pill--accent"><?php echo count($mobileApps['rules']); ?> <?php echo count($mobileApps['rules']) === 1 ? 'rule' : 'rules'; ?></span>
        </div>
        <div class="p202-panel__body">
            <?php if ($mobileApps['rules'] === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-sliders p202-empty__icon"></i>
                    <strong class="p202-empty__title">No rules yet, so nothing decodes</strong>
                    <div>Most apps start with install, trial and purchase on fine values 1, 10 and 40, and the three coarse buckets. Add those now and edit them to match what your app reports.</div>
                    <?php if ($canManage) { ?>
                        <div class="p202-empty__action">
                            <form method="post" action="<?php echo $e($self); ?>">
                                <?php echo $mobileApps['csrf']; ?>
                                <input type="hidden" name="action" value="starter_schema">
                                <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
                                <input type="hidden" name="app_id" value="<?php echo $appStoreId; ?>">
                                <button class="btn btn-primary btn-sm" type="submit">Use starter schema</button>
                            </form>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <div class="p202-table-wrap">
                    <table class="table table-hover p202-table">
                        <thead><tr><th>Kind</th><th>Value</th><th>Event</th><th class="num">Revenue</th><?php if ($canManage) { ?><th></th><?php } ?></tr></thead>
                        <tbody>
                        <?php foreach ($mobileApps['rules'] as $rule) { ?>
                            <tr>
                                <td><?php echo $rule['fine_value'] === null ? 'Coarse' : 'Fine'; ?></td>
                                <td><?php echo $e($rule['fine_value'] === null ? (string)$rule['coarse_value'] : (string)$rule['fine_value']); ?></td>
                                <td><?php echo $e($rule['event_name']); ?></td>
                                <td class="num"><?php echo $e(number_format((float)$rule['revenue'], 2)); ?></td>
                                <?php if ($canManage) { ?>
                                    <td class="num">
                                        <a class="p202-list__action" href="<?php echo $e($self . '?app=' . $rowId . '&rule_edit=' . (int)$rule['rule_id']); ?>">edit</a>
                                        <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Remove this rule? Postbacks already decoded keep the event they were given; new ones stop decoding for this value.">
                                            <?php echo $mobileApps['csrf']; ?>
                                            <input type="hidden" name="action" value="rule_remove">
                                            <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
                                            <input type="hidden" name="rule_id" value="<?php echo (int)$rule['rule_id']; ?>">
                                            <button class="p202-list__action p202-list__action--danger" type="submit">remove</button>
                                        </form>
                                    </td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>

            <?php if ($canManage) {
                $kind = $editRule !== null ? ($editRule['fine_value'] === null ? 'coarse' : 'fine') : (string)($form['kind'] ?? 'fine'); ?>
                <form method="post" action="<?php echo $e($self); ?>" class="p202-section">
                    <?php echo $mobileApps['csrf']; ?>
                    <input type="hidden" name="action" value="rule_save">
                    <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
                    <input type="hidden" name="app_id" value="<?php echo $appStoreId; ?>">
                    <?php if ($editRule !== null) { ?><input type="hidden" name="rule_id" value="<?php echo (int)$editRule['rule_id']; ?>"><?php } ?>
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <span class="form-label d-block">Kind</span>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="kind" id="kind_fine" value="fine" <?php echo $kind === 'fine' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="kind_fine">Fine value</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="kind" id="kind_coarse" value="coarse" <?php echo $kind === 'coarse' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="kind_coarse">Coarse</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-2" data-kind-fine>
                            <label class="form-label" for="fine_value">Fine value</label>
                            <select class="form-select<?php echo $invalid('fine_value'); ?>" id="fine_value" name="fine_value">
                                <?php for ($i = 0; $i <= 63; $i++) {
                                    $selected = $editRule !== null && (int)($editRule['fine_value'] ?? -1) === $i; ?>
                                    <option value="<?php echo $i; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php } ?>
                            </select>
                            <?php echo $fieldError('fine_value'); ?>
                        </div>
                        <div class="col-6 col-md-2" data-kind-coarse hidden>
                            <label class="form-label" for="coarse_value">Coarse value</label>
                            <select class="form-select<?php echo $invalid('coarse_value'); ?>" id="coarse_value" name="coarse_value">
                                <?php foreach (['low', 'medium', 'high'] as $coarse) { ?>
                                    <option value="<?php echo $coarse; ?>" <?php echo $editRule !== null && (string)($editRule['coarse_value'] ?? '') === $coarse ? 'selected' : ''; ?>><?php echo $coarse; ?></option>
                                <?php } ?>
                            </select>
                            <?php echo $fieldError('coarse_value'); ?>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="event_name">Event</label>
                            <input class="form-control<?php echo $invalid('event_name'); ?>" type="text" id="event_name" name="event_name" value="<?php echo $e($editRule['event_name'] ?? ($form['event_name'] ?? '')); ?>" placeholder="purchase" maxlength="255" required>
                            <?php echo $fieldError('event_name'); ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="revenue">Revenue</label>
                            <input class="form-control<?php echo $invalid('revenue'); ?>" type="text" inputmode="decimal" id="revenue" name="revenue" value="<?php echo $e($editRule !== null ? number_format((float)$editRule['revenue'], 2, '.', '') : ($form['revenue'] ?? '0.00')); ?>">
                            <?php echo $fieldError('revenue'); ?>
                        </div>
                    </div>
                    <div class="p202-form-actions">
                        <button class="btn btn-primary" type="submit"><?php echo $editRule !== null ? 'Save rule' : 'Add rule'; ?></button>
                        <?php if ($editRule !== null) { ?><a class="btn btn-link" href="<?php echo $e($self . '?app=' . $rowId); ?>">Cancel</a><?php } ?>
                    </div>
                    <p class="form-text mb-0">A rule maps exactly one value. Each fine value and each coarse value can be used once per app.</p>
                </form>
            <?php } ?>

            <?php if ($mobileApps['defaultRules'] !== []) { ?>
                <details class="p202-disclosure" data-p202-remember="setup-mobile-apps-defaults">
                    <summary>Account-wide default rules <span class="p202-disclosure__hint"><?php echo count($mobileApps['defaultRules']); ?> apply to apps with no rule of their own</span></summary>
                    <div class="p202-disclosure__body">
                        <div class="p202-table-wrap">
                            <table class="table table-hover p202-table">
                                <thead><tr><th>Kind</th><th>Value</th><th>Event</th><th class="num">Revenue</th></tr></thead>
                                <tbody>
                                <?php foreach ($mobileApps['defaultRules'] as $rule) { ?>
                                    <tr class="text-body-secondary">
                                        <td><?php echo $rule['fine_value'] === null ? 'Coarse' : 'Fine'; ?></td>
                                        <td><?php echo $e($rule['fine_value'] === null ? (string)$rule['coarse_value'] : (string)$rule['fine_value']); ?></td>
                                        <td><?php echo $e($rule['event_name']); ?> <span class="p202-pill">default</span></td>
                                        <td class="num"><?php echo $e(number_format((float)$rule['revenue'], 2)); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="p202-help">This app's own rule for a value always wins over the account-wide one.</p>
                    </div>
                </details>
            <?php } ?>
        </div>
    </section>

    <section class="p202-panel">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Schema token &amp; SDK</h2>
            <p class="p202-panel__sub">Devices fetch this app's conversion values with the token.</p>
        </div>
        <div class="p202-panel__body">
            <div class="p202-code">
                <span class="p202-code__value p202-code__value--masked" id="schema-token"
                      data-p202-value="<?php echo $e($token); ?>"
                      data-p202-masked="<?php echo $e($masked); ?>"><?php echo $e($masked); ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-p202-reveal="#schema-token">Reveal</button>
                <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($token); ?>">Copy</button>
                <?php if ($canManage) { ?>
                    <form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="Replace this schema token? The old token stops working immediately. Apps keep their last cached schema until they fetch with the new one.">
                        <?php echo $mobileApps['csrf']; ?>
                        <input type="hidden" name="action" value="rotate_token">
                        <input type="hidden" name="attribution_app_id" value="<?php echo $rowId; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Rotate…</button>
                    </form>
                <?php } ?>
            </div>

            <h3 class="p202-panel__title mt-4">Info.plist keys</h3>
            <?php
            $plist = "<key>NSAdvertisingAttributionReportEndpoint</key>\n<string>{$origin}</string>\n"
                . "<key>AttributionCopyEndpoint</key>\n<string>{$origin}</string>\n"
                . "<key>EligibleForAdAttributionKitReengagementPostbackCopies</key>\n<true/>";
            ?>
            <div class="p202-code">
                <pre class="p202-code__value mb-0"><?php echo $e($plist); ?></pre>
                <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e($plist); ?>">Copy</button>
            </div>

            <h3 class="p202-panel__title mt-4">Swift package: P202Attribution</h3>
            <?php
            $swift = "let attribution = P202Attribution(\n    endpoint: URL(string: \"{$origin}\")!,\n    schemaToken: \"" . ($token === '' ? '' : mb_substr($token, 0, 4) . '…' . mb_substr($token, -4)) . "\")\nattribution.logEvent(\"purchase\")";
            ?>
            <div class="p202-code">
                <pre class="p202-code__value mb-0"><?php echo $e($swift); ?></pre>
                <button type="button" class="btn btn-sm btn-outline-secondary p202-copy" data-p202-copy="<?php echo $e(str_replace(mb_substr($token, 0, 4) . '…' . mb_substr($token, -4), $token, $swift)); ?>">Copy</button>
            </div>
            <p class="p202-help">Copy puts the whole token in; the snippet shows it shortened so a screenshot of this page does not leak it.</p>
        </div>
    </section>

    <section class="p202-panel">
        <div class="p202-panel__head">
            <h2 class="p202-panel__title">Recent postbacks</h2>
            <p class="p202-panel__sub">The newest ten Apple sent for this app, whatever their signature.</p>
        </div>
        <div class="p202-panel__body">
            <?php if ($mobileApps['recent'] === null) { ?>
                <div class="alert alert-warning p202-flash" role="status">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div class="p202-flash__body">The postbacks for this app could not be read just now, so this list is not showing whether any have arrived. Reload the page; if it keeps happening the server log has the reason.</div>
                </div>
            <?php } elseif ($mobileApps['recent'] === []) { ?>
                <div class="p202-empty">
                    <i class="bi bi-inbox p202-empty__icon"></i>
                    <strong class="p202-empty__title">Nothing received yet</strong>
                    <div>Apple sends a postback a day or more after an install, and only when a campaign wins attribution. Ship a build with the Info.plist keys above, then check back.</div>
                </div>
            <?php } else { ?>
                <div class="p202-table-wrap">
                    <table class="table table-hover p202-table">
                        <thead><tr><th>Received</th><th>Protocol</th><th>Ad network</th><th>Type</th><th>Value</th><th>Signature</th></tr></thead>
                        <tbody>
                        <?php foreach ($mobileApps['recent'] as $postback) {
                            $state = (string)($postback['signature_state'] ?? '');
                            $tone = ['valid' => 'p202-pill--good', 'invalid' => 'p202-pill--bad', 'development' => 'p202-pill--warn'][$state] ?? ''; ?>
                            <tr>
                                <td><?php echo $e(date('M j, H:i', (int)$postback['received_at'])); ?></td>
                                <td><?php echo $e((string)($postback['protocol'] ?? '')); ?> <?php echo $e((string)($postback['version'] ?? '')); ?></td>
                                <td><?php echo $e((string)($postback['ad_network_id'] ?? '')); ?></td>
                                <td><?php echo $e((string)($postback['conversion_type'] ?? '')); ?></td>
                                <td><?php echo $e($postback['conversion_value'] ?? ($postback['coarse_conversion_value'] ?? '')); ?></td>
                                <td><span class="p202-pill <?php echo $tone; ?>"><?php echo $e($state); ?></span></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </section>
<?php } ?>

<script>
/* Three things only, as the page design says: the receiver check, the
   fine/coarse swap, and the copy buttons (those come from p202-ui.js). */
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
            pill.textContent = 'Checking…';
            pill.className = 'p202-pill';
            if (url.indexOf('https:') !== 0) {
                /* Apple calls the receiver over public HTTPS on port 443 and
                   nothing else, so a non-HTTPS origin cannot work however the
                   fetch below turns out. Saying "Ready" here because the
                   browser reached it would be a green light for a URL Apple
                   will never call — the check keyed on the PAGE's protocol
                   instead, which warned only when HTTPS was configured. */
                pill.textContent = 'Apple requires HTTPS';
                pill.className = 'p202-pill p202-pill--warn';
                pill.title = 'Apple only calls this endpoint over HTTPS on port 443. This install advertises '
                    + url + '. Reachable from here, but not from Apple.';
                settle();
                return;
            }
            fetch(url, { method: 'GET', credentials: 'omit' }).then(function (response) {
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
                pill.title = 'Apple needs this URL on public HTTPS, port 443.';
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
        if (document.querySelector('[data-receiver-strip]')) {
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
