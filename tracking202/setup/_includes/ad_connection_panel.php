<?php

declare(strict_types=1);

use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;

/**
 * Setup › Destinations' ChatGPT Ads panel: the ad accounts connected with
 * an Advertiser API key, each one's check and setup, and the form that
 * connects one. A pure function of its arguments; destinations.php reads
 * the request and the database. Keys are typed into password fields and
 * never shown again.
 */

/**
 * @param list<array<string, mixed>> $connections as AdConnectionStore::list() shapes them
 * @param array<int, string> $ppcAccounts traffic source accounts by id, for the link
 * @param array{id: int, steps: list<array<string, mixed>>}|null $lastSetup the setup just run, shown under its connection
 * @param array<string, string> $errors the refused connect form's, by field
 */
function p202_ad_connections_panel(array $connections, array $ppcAccounts, ?array $lastSetup, array $errors, string $self, string $token): string
{
    $e = 'p202_setup_e';
    $out = '<section class="p202-panel mt-4" id="chatgpt-ads">'
        . '<div class="p202-panel__head"><h2 class="p202-panel__title">ChatGPT Ads</h2><span class="p202-panel__sub">Advertiser API connection</span></div>'
        . '<div class="p202-panel__body">';
    foreach ($connections as $c) {
        $id = (int) $c['connection_id'];
        $account = (array) $c['account'];
        $setup = (array) $c['setup'];
        $pixel = is_array($setup['pixel'] ?? null) ? $setup['pixel'] : null;
        $out .= '<div class="mb-4" id="ac' . $id . '"><div class="p202-list__item">'
            . '<span class="p202-list__name">' . $e($account['name'] ?? '') . '</span>'
            . '<span class="p202-pill">' . $e($account['id'] ?? '') . '</span>'
            . '<span class="p202-pill' . ($c['status'] === 'connected' ? ' p202-pill--accent' : '') . '">' . $e(str_replace('_', ' ', (string) $c['status'])) . '</span>'
            . '<span class="p202-list__actions">';
        foreach (['ad_check' => 'check key', 'ad_delete' => 'delete'] as $act => $label) {
            $out .= '<form method="post" action="' . $e($self) . '" class="d-inline"' . ($act === 'ad_delete' ? ' data-p202-confirm="Delete this connection? Its keys are erased here; nothing is removed at OpenAI."' : '') . '>'
                . p202_setup_token_field($token) . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="connection_id" value="' . $id . '">'
                . '<button type="submit" class="p202-list__action' . ($act === 'ad_delete' ? ' p202-list__action--danger' : '') . '">' . $label . '</button></form>';
        }
        $out .= '</span><span class="p202-list__meta">' . $e(($account['currency'] ?? '') . ' · ' . ($account['timezone'] ?? ''))
            . ($c['ppc_account_id'] !== null ? ' · traffic source account ' . $e($ppcAccounts[(int) $c['ppc_account_id']] ?? '#' . (int) $c['ppc_account_id']) : '')
            . ' · pixel ' . $e($pixel !== null ? (string) ($pixel['pixel_id'] ?? '') : 'not set up')
            . ' · Conversions API key ' . (($c['credentials_set']['conversions_api_key'] ?? false) ? 'stored' : 'not set up')
            . '</span></div>';
        if (($c['currency_matches_account'] ?? true) === false) {
            $out .= p202_flash('info', 'This ad account bills in ' . (string) ($account['currency'] ?? '') . ', not your account\'s currency. Conversions are sent as they are; spend is not converted, so spend sync is refused for it.');
        }
        if (is_string($c['last_error'] ?? null) && $c['last_error'] !== '') {
            $out .= p202_flash('bad', 'Last check: ' . $c['last_error']);
        }
        if ($lastSetup !== null && $lastSetup['id'] === $id) {
            $out .= '<ul class="list-unstyled mb-3">';
            foreach ($lastSetup['steps'] as $step) {
                $out .= '<li><strong>' . $e(str_replace('_', ' ', (string) $step['step'])) . '</strong>: ' . $e((string) $step['outcome']) . '. ' . $e((string) $step['detail']) . '</li>';
            }
            $out .= '</ul>';
        }
        $out .= '<form method="post" action="' . $e($self) . '#ac' . $id . '" autocomplete="off">' . p202_setup_token_field($token)
            . '<input type="hidden" name="action" value="ad_setup"><input type="hidden" name="connection_id" value="' . $id . '">'
            . '<div class="mb-2">';
        foreach (['pixel' => 'Find or create the web pixel', 'conversions_key' => 'Create a Conversions API key', 'destination' => 'Create the ChatGPT Ads destination', 'event_settings' => 'Create event settings (30-day window)'] as $step => $label) {
            $out .= '<div class="form-check"><input class="form-check-input" type="checkbox" id="ac' . $id . '_' . $step . '" name="steps[]" value="' . $step . '" checked>'
                . '<label class="form-check-label" for="ac' . $id . '_' . $step . '">' . $e($label) . '</label></div>';
        }
        $out .= '</div><details class="p202-disclosure mb-3"><summary>Advanced <span class="p202-disclosure__hint">pixel and key from Ads Manager, more event types</span></summary><div class="p202-disclosure__body">'
            . '<div class="form-text mb-2">Only when OpenAI has pixel or key creation switched off for the account (setup then says so), or the account has several pixels.</div>';
        foreach ([['pixel_source_id', 'One of the account\'s pixels, by source id', 'clidsrc_…', 'text'], ['pixel_id', 'Pixel ID from Ads Manager', '', 'text'], ['source_id', 'That pixel\'s source id', 'clidsrc_…', 'text'], ['conversions_api_key', 'Conversions API key from Ads Manager', '', 'password']] as [$name, $label, $placeholder, $type]) {
            $out .= '<div class="mb-2"><label class="form-label" for="ac' . $id . '_' . $name . '">' . $e($label) . '</label>'
                . '<input type="' . $type . '" class="form-control" id="ac' . $id . '_' . $name . '" name="' . $name . '" placeholder="' . $e($placeholder) . '"></div>';
        }
        $out .= '<label class="form-label" for="ac' . $id . '_events">Event types</label><select class="form-select" multiple id="ac' . $id . '_events" name="event_types[]">';
        foreach (OpenAiAdsConnections::EVENT_TYPES as $type) {
            $out .= '<option value="' . $type . '">' . $e(str_replace('_', ' ', $type)) . '</option>';
        }
        $out .= '</select><div class="form-text">None picked: the destination\'s event types, else order created.</div></div></details>'
            . '<div class="p202-form-actions"><button type="submit" class="btn btn-outline-primary btn-sm">Run setup</button></div></form></div>';
    }
    $out .= '<form method="post" action="' . $e($self) . '#chatgpt-ads" autocomplete="off">' . p202_setup_token_field($token)
        . '<input type="hidden" name="action" value="ad_connect">'
        . '<div class="form-text mb-3">Paste the Advertiser API key from ChatGPT Ads Manager › Settings. Prosper202 checks it with OpenAI and fills the account\'s name, currency and time zone; then Run setup makes the pixel, Conversions API key and event settings for you.</div>'
        . '<div class="mb-3"><label class="form-label" for="ad_api_key">Advertiser API key</label>'
        . '<input type="password" class="form-control' . p202_setup_invalid($errors, 'api_key') . '" id="ad_api_key" name="api_key">'
        . p202_setup_feedback($errors, 'api_key') . '<div class="form-text">Stored encrypted, never shown again.</div></div>'
        . '<div class="mb-3"><label class="form-label" for="ad_ppc_account_id">Traffic source account</label>'
        . '<select class="form-select' . p202_setup_invalid($errors, 'ppc_account_id') . '" id="ad_ppc_account_id" name="ppc_account_id">'
        . p202_setup_options($ppcAccounts, '', 'None') . '</select>' . p202_setup_feedback($errors, 'ppc_account_id') . '</div>'
        . '<div class="p202-form-actions"><button type="submit" class="btn btn-primary">Connect ChatGPT Ads</button></div></form>'
        . '<div class="form-text mt-3">The same from a script: <code>p202 ad-connection</code> or <code>/api/v3/ad-connections</code>.</div>'
        . '</div></section>';

    return $out;
}

/**
 * Whether a ChatGPT Ads connection reaches this destination's pixel (it made the destination, or setup found or was
 * given the pixel): then its real test event can be read back.
 *
 * @param list<array<string, mixed>> $connections
 * @param array<string, mixed> $destination as DestinationStore::list() shapes it
 */
function p202_ad_connection_reaches(array $connections, array $destination): bool
{
    if (($destination['type'] ?? '') !== 'openai_ads') {
        return false;
    }
    $pixel = ((array) $destination['settings'])['pixel_id'] ?? null;
    foreach ($connections as $c) {
        $setup = (array) $c['setup'];
        if (($setup['destination_id'] ?? null) === (int) $destination['destination_id']
            || (is_array($setup['pixel'] ?? null) && ($setup['pixel']['pixel_id'] ?? null) === $pixel)) {
            return true;
        }
    }

    return false;
}
