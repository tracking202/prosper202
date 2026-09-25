<?php

declare(strict_types=1);

/**
 * Analyze › Mobile Apps: the iOS postbacks behind the report's totals, a page
 * at a time.
 *
 * @var array<string, mixed> $mobileReport
 * @var callable $e
 * @var callable $num
 * @var callable $link
 * @var callable $empty
 * @var callable $signatureTone
 * @var array<string, array{name: string, key: string, platform: string}> $appNames
 * @var string $notGiven
 * @var string $setupUrl
 */
?>
<?php $rows = $mobileReport['postbacks'];
$pagination = $mobileReport['pagination']; ?>
<?php if ($rows === null) { ?>
    <?php echo $empty(
        'bi-exclamation-triangle',
        'The postbacks could not be read',
        'The message above says why. This is not a statement that none have arrived.'
    ); ?>
<?php } elseif ($rows === []) { ?>
    <?php echo $empty(
        'bi-inbox',
        'No postbacks in this range',
        'Widen the range, or check that the receivers are reachable.',
        'Check the receivers',
        $setupUrl
    ); ?>
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
                            $rowAppName = $appNames[(string)($row['registration_id'] ?? '')]['name'] ?? '';
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

        <?php /* Rows, not postbacks: list() counts stored rows while the
                 Report tab counts one per replayed postback, so calling
                 both "postbacks" makes the two tabs look like they
                 disagree about the same number. */ ?>
        <p class="text-secondary small">
            <?php echo $num($pagination['rows']); ?>
            <?php echo $pagination['rows'] === 1 ? 'row' : 'rows'; ?> in this range.
            A postback replayed with different unsigned values is stored as its own row,
            so this can exceed the Report tab's postback count.
        </p>

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
