<?php

declare(strict_types=1);

use Prosper202\AdPlatforms\OpenAiAds\TrackingTemplate;

/**
 * Setup › Destinations › ChatGPT Ads › campaigns: one connection's campaigns
 * as OpenAI has them now, with pause and activate, the tracking template and
 * the event settings, yesterday's spend, and one campaign's ad groups and
 * ads with their review status. A pure function of its arguments;
 * destinations.php reads the request and calls OpenAI.
 *
 * @param array<string, mixed> $connection as AdConnectionStore::get() shapes it
 * @param list<array<string, mixed>>|null $campaigns null when they could not be read ($error says why)
 * @param array<string, mixed>|null $spend OpenAiAdsCampaigns::spend(), null when not read
 * @param array{campaign: string, groups: list<array{group: array<string, mixed>, ads: list<array<string, mixed>>}>}|null $detail
 */
function p202_ad_campaigns_view(array $connection, ?array $campaigns, ?string $error, ?array $spend, ?string $spendError, ?array $detail, ?string $detailError, string $self, string $token): string
{
    $e = 'p202_setup_e';
    $id = (int) $connection['connection_id'];
    $account = (array) $connection['account'];
    $base = $self . '?ad_campaigns=' . $id;
    $currency = (string) ($account['currency'] ?? '');
    $money = static fn (mixed $micros): string => is_int($micros) ? $currency . ' ' . number_format($micros / 1_000_000, 2) : '—';
    $spentBy = [];
    foreach ((array) ($spend['campaigns'] ?? []) as $row) {
        $spentBy[(string) $row['campaign_id']] = $row;
    }
    $default = TrackingTemplate::default();
    $issues = static fn (array $codes): string => $codes === [] ? 'none' : str_replace('_', ' ', implode(', ', $codes));
    $form = static function (string $action, string $level, string $objectId, string $label, string $confirm = '') use ($e, $self, $id, $token): string {
        return '<form method="post" action="' . $e($self . '?ad_campaigns=' . $id) . '" class="d-inline"' . ($confirm !== '' ? ' data-p202-confirm="' . $e($confirm) . '"' : '') . '>'
            . p202_setup_token_field($token)
            . '<input type="hidden" name="action" value="' . $e($action) . '"><input type="hidden" name="connection_id" value="' . $id . '">'
            . '<input type="hidden" name="level" value="' . $e($level) . '"><input type="hidden" name="object_id" value="' . $e($objectId) . '">'
            . '<button type="submit" class="p202-list__action">' . $e($label) . '</button></form>';
    };
    $statusActions = static function (string $level, array $row) use ($form): string {
        $object = (string) $row['id'];

        return ($row['status'] ?? '') === 'active'
            ? $form('ad_campaign_pause', $level, $object, 'pause', 'Pause ' . $object . ' at OpenAI? It stops serving at once.')
            : $form('ad_campaign_activate', $level, $object, 'activate', 'Activate ' . $object . ' at OpenAI? It can start spending at once.');
    };
    $out = '<section class="p202-panel" id="ad-campaigns"><div class="p202-panel__head">'
        . '<h2 class="p202-panel__title">ChatGPT Ads campaigns · ' . $e($account['name'] ?? '') . '</h2>'
        . '<span class="p202-panel__sub">' . $e(($account['id'] ?? '') . ' · ' . $currency . ' · ' . ($account['timezone'] ?? '')) . ' · <a href="' . $e($self . '#ac' . $id) . '">back</a></span></div>'
        . '<div class="p202-panel__body">';
    if ($spendError !== null) {
        $out .= p202_flash('bad', 'Spend was not read: ' . $spendError);
    } elseif ($spend !== null) {
        $out .= '<div class="form-text mb-3">Yesterday (' . $e((string) $spend['since']) . ', the ad account\'s day) ChatGPT Ads spent '
            . $e($currency . ' ' . number_format((float) $spend['total']['spend'], 2)) . ' over ' . number_format((int) $spend['total']['clicks']) . ' clicks. '
            . $e((string) $spend['note']) . '</div>';
    }
    if ($error !== null) {
        $out .= p202_flash('bad', 'The campaigns were not read: ' . $error);
    } elseif ($campaigns === []) {
        $out .= '<div class="p202-empty"><i class="bi bi-megaphone p202-empty__icon"></i><strong class="p202-empty__title">No campaigns yet</strong>'
            . '<div>Campaigns made in ChatGPT Ads Manager show here.</div></div>';
    } elseif ($campaigns !== null) {
        $out .= '<div class="p202-table-wrap"><table class="table p202-table"><thead><tr><th>Campaign</th><th>Status</th><th>Serving issues</th>'
            . '<th class="num">Daily budget</th><th class="num">Spent yesterday</th><th>Tracking template</th><th>Event settings</th><th></th></tr></thead><tbody>';
        foreach ($campaigns as $c) {
            $cid = (string) $c['id'];
            $template = $c['query_string_template'];
            $out .= '<tr id="c-' . $e($cid) . '"><td><a href="' . $e($base . '&campaign=' . rawurlencode($cid) . '#c-' . $cid) . '">' . $e($c['name'] ?? $cid) . '</a><div class="form-text">' . $e($cid) . '</div></td>'
                . '<td><span class="p202-pill' . (($c['status'] ?? '') === 'active' ? ' p202-pill--accent' : '') . '">' . $e((string) ($c['status'] ?? '')) . '</span></td>'
                . '<td>' . $e($issues($c['serving_issues'])) . '</td>'
                . '<td class="num">' . $e($money($c['budget']['daily_spend_limit_micros'] ?? null)) . '</td>'
                . '<td class="num">' . $e(isset($spentBy[$cid]) ? $currency . ' ' . number_format((float) $spentBy[$cid]['spend'], 2) : '—') . '</td>'
                . '<td>' . $e($template === null ? 'not set' : ($template === $default ? 'the Get Links one' : 'its own')) . '</td>'
                . '<td>' . count($c['conversion_event_setting_ids']) . '</td>'
                . '<td class="text-nowrap">' . $statusActions('campaigns', $c)
                . ($template === $default ? '' : ' ' . $form('ad_campaign_template', 'campaigns', $cid, 'push template', 'Change ' . $cid . '\'s tracking template to the Get Links one? OpenAI appends it to every ad\'s URL.'))
                . ' ' . $form('ad_campaign_events', 'campaigns', $cid, 'attach event settings') . '</td></tr>';
        }
        $out .= '</tbody></table></div>'
            . '<div class="form-text">The Get Links template is <code>' . $e($default) . '</code>; for a tracker on a landing page, Get Links adds its t202id. Event settings attached are the ones setup made, added to the campaign\'s own. The same from a script: <code>p202 ad-campaign</code>.</div>';
    }
    if ($detailError !== null) {
        $out .= p202_flash('bad', 'Its ad groups and ads were not read: ' . $detailError);
    } elseif ($detail !== null) {
        $out .= '<h3 class="p202-panel__title mt-4">' . $e($detail['campaign']) . ': ad groups and ads</h3>';
        if ($detail['groups'] === []) {
            $out .= '<div class="form-text">This campaign has no ad groups.</div>';
        }
        foreach ($detail['groups'] as $entry) {
            $g = $entry['group'];
            $out .= '<div class="p202-list__item mt-3"><span class="p202-list__name">' . $e($g['name'] ?? $g['id']) . '</span><span class="p202-pill">' . $e((string) $g['status']) . '</span>'
                . '<span class="p202-list__actions">' . $statusActions('ad-groups', $g) . '</span>'
                . '<span class="p202-list__meta">' . $e($g['id'] . ' · serving issues: ' . $issues($g['serving_issues'])) . '</span></div>';
            if ($entry['ads'] !== []) {
                $out .= '<div class="p202-table-wrap"><table class="table p202-table"><thead><tr><th>Ad</th><th>Status</th><th>Review</th><th>Serving issues</th><th></th></tr></thead><tbody>';
                foreach ($entry['ads'] as $ad) {
                    $out .= '<tr><td>' . $e($ad['name'] ?? $ad['id']) . '<div class="form-text">' . $e((string) $ad['id']) . '</div></td><td>' . $e((string) $ad['status']) . '</td>'
                        . '<td>' . $e(trim((string) ($ad['review_status'] ?? '') . ' ' . str_replace('_', ' ', (string) ($ad['review_reason'] ?? '')))) . '</td>'
                        . '<td>' . $e($issues($ad['serving_issues'])) . '</td>'
                        . '<td class="text-nowrap">' . $statusActions('ads', $ad) . '</td></tr>';
                }
                $out .= '</tbody></table></div>';
            }
        }
    }

    return $out . '</div></section>';
}

/**
 * The pause a money-loss alert offers, previewed: what OpenAI says of the
 * campaign now and the status it would get, with a confirmed Pause it.
 *
 * @param array<string, mixed>|null $preview OpenAiAdsCampaigns::setStatus(..., dryRun: true)
 */
function p202_ad_campaigns_pause_preview(int $connectionId, ?array $preview, ?string $error, string $self, string $token): string
{
    if ($error !== null) {
        return p202_flash('bad', 'The campaign to pause was not read: ' . $error);
    }
    if ($preview === null) {
        return '';
    }
    $e = 'p202_setup_e';
    $record = (array) $preview['record'];
    $id = (string) ($record['id'] ?? '');
    $change = (array) ($preview['change']['status'] ?? []);
    if (($change['from'] ?? null) === ($change['to'] ?? null)) {
        return p202_flash('info', ($record['name'] ?? $id) . ' (' . $id . ') is already paused at OpenAI.');
    }

    return '<section class="p202-panel mb-4" id="pause-preview"><div class="p202-panel__head"><h2 class="p202-panel__title">Pause '
        . $e($record['name'] ?? $id) . '?</h2><span class="p202-panel__sub">' . $e($id) . '</span></div><div class="p202-panel__body">'
        . '<div class="mb-3">At OpenAI now: ' . $e((string) ($change['from'] ?? '')) . '. Pausing makes it ' . $e((string) ($change['to'] ?? 'paused'))
        . ': it stops serving and stops spending at once. Nothing has been changed yet.</div>'
        . '<form method="post" action="' . $e($self . '?ad_campaigns=' . $connectionId) . '" data-p202-confirm="' . $e('Pause ' . $id . ' at OpenAI now?') . '">'
        . p202_setup_token_field($token)
        . '<input type="hidden" name="action" value="ad_campaign_pause"><input type="hidden" name="connection_id" value="' . $connectionId . '">'
        . '<input type="hidden" name="level" value="campaigns"><input type="hidden" name="object_id" value="' . $e($id) . '">'
        . '<button type="submit" class="btn btn-danger">Pause it</button></form></div></section>';
}
