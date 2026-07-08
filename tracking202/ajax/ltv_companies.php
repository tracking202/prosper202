<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Company (ABM account) management: every company entity with live contact
 * and revenue rollups, plus rename, email-domain assignment (drives customer
 * auto-attach), merge and delete-if-empty. Company names click through to
 * the engagement drill-down.
 */

$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');
$offset = max(0, (int) ($_POST['offset'] ?? 0));
$limit = 50;

require_once __DIR__ . '/ltv_ui.php';
$money = p202_ltv_money(...);
$esc = p202_ltv_esc(...);
$when = p202_ltv_when(...);

$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_companies.php';

$notice = null;
$error = null;

try {
    $conn = new \Prosper202\Database\Connection($db);
    $companiesRepo = new \Prosper202\Ltv\MysqlCompanyRepository($conn);

    if ($action !== '') {
        if (!AUTH::check_csrf_token()) {
            $error = 'Your session token was invalid — please try again.';
        } else {
            try {
                switch ($action) {
                    case 'add_company':
                        $companiesRepo->resolveOrCreate($userId, (string) ($_POST['company_name'] ?? ''));
                        $notice = 'Company saved.';
                        break;

                    case 'update_company':
                        // One atomic update: name and domain are validated
                        // together before anything commits, so a rejected
                        // domain can never leave a half-applied rename.
                        $companiesRepo->update($userId, (int) ($_POST['company_id'] ?? 0), [
                            'name' => (string) ($_POST['company_name'] ?? ''),
                            'domain' => (string) ($_POST['company_domain'] ?? ''),
                        ]);
                        $notice = 'Company updated. Customers with matching email domains will auto-attach.';
                        break;

                    case 'merge_company':
                        $companiesRepo->merge($userId, (int) ($_POST['source_company_id'] ?? 0), (int) ($_POST['company_id'] ?? 0));
                        $notice = 'Companies merged.';
                        break;

                    case 'delete_company':
                        $companiesRepo->delete($userId, (int) ($_POST['company_id'] ?? 0));
                        $notice = 'Company deleted.';
                        break;

                    default:
                        throw new \RuntimeException('Unknown action.');
                }
            } catch (\RuntimeException $actionError) {
                $error = $actionError->getMessage();
            }
        }
    }

    $list = $companiesRepo->listWithRollups($userId, $limit, $offset);
} catch (\Throwable $e) {
    error_log('ltv_companies: ' . $e->getMessage());
    echo '<div class="alert alert-warning">Company data is unavailable. '
        . 'Run the LTV migration (or the 1.9.70 upgrade) if you have not yet.</div>';
    return;
}

$csrfToken = (string) ($_SESSION['token'] ?? '');
$editId = (int) ($_POST['edit'] ?? 0);
if ($error !== null && $action === 'update_company') {
    $editId = (int) ($_POST['company_id'] ?? 0);
}
?>

<?php echo p202_ltv_ui_styles(); ?>
<?php echo p202_ltv_tabs('companies'); ?>

<?php if ($notice !== null && $error === null) { ?>
    <?php echo p202_ltv_flash('success', $esc($notice)); ?>
<?php } ?>
<?php if ($error !== null) { ?>
    <?php echo p202_ltv_flash('error', $esc($error)); ?>
<?php } ?>

<?php echo p202_ltv_card_open('Companies',
    number_format((int) $list['total']) . ' account(s) — customers attach by company name or email domain'); ?>
    <div class="ltv-table-wrap">
        <table class="ltv-table ltv-table-hover">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Email Domain</th>
                    <th class="num">Contacts</th>
                    <th class="num">Orders</th>
                    <th class="num">Revenue</th>
                    <th class="num">MRR</th>
                    <th>Last Activity</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list['rows'] === []) { ?>
                    <tr><td colspan="8">
                        <?php echo p202_ltv_empty('fa-building-o', 'No companies yet',
                            'They are created when a customer gets a company name, by the nightly linking sweep, or manually below.'); ?>
                    </td></tr>
                <?php } ?>
                <?php foreach ($list['rows'] as $companyRow) {
                    $companyId = (int) $companyRow['company_id'];
                    if ($companyId === $editId) {
                ?>
                    <tr id="ltv-company-row-<?php echo $companyId; ?>">
                        <td>
                            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
                            <input type="hidden" name="company_id" value="<?php echo $companyId; ?>" />
                            <input type="text" class="ltv-input ltv-input-sm" name="company_name" maxlength="255"
                                value="<?php echo $esc($companyRow['name'] ?? ''); ?>">
                        </td>
                        <td><input type="text" class="ltv-input ltv-input-sm" name="company_domain" maxlength="191"
                                placeholder="example.com" value="<?php echo $esc($companyRow['domain'] ?? ''); ?>"></td>
                        <td class="num"><?php echo number_format((int) ($companyRow['contacts'] ?? 0)); ?></td>
                        <td class="num"><?php echo number_format((int) ($companyRow['order_count'] ?? 0)); ?></td>
                        <td class="num">$<?php echo $money($companyRow['total_revenue'] ?? 0); ?></td>
                        <td class="num">$<?php echo $money($companyRow['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($companyRow['last_activity_time'] ?? 0); ?></td>
                        <td class="num" style="white-space: nowrap;">
                            <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-primary" onclick="ltvCompanySave(<?php echo $companyId; ?>);">Save</button>
                            <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvCompaniesLoad(<?php echo $offset; ?>);">Cancel</button>
                        </td>
                    </tr>
                <?php } else { ?>
                    <tr>
                        <td class="ltv-row-link" onclick="ltvCompanyView(this.getAttribute('data-company'));"
                            data-company="<?php echo $esc($companyRow['name'] ?? ''); ?>" title="View engagement drill-down">
                            <a href="#" onclick="return false;" class="ltv-strong"><?php echo $esc($companyRow['name'] ?? ''); ?></a>
                            <span class="ltv-dim" style="font-size: 12px;">#<?php echo $companyId; ?></span>
                        </td>
                        <td><?php echo $esc($companyRow['domain'] ?? '') ?: '<span class="ltv-dim">—</span>'; ?></td>
                        <td class="num"><?php echo number_format((int) ($companyRow['contacts'] ?? 0)); ?></td>
                        <td class="num"><?php echo number_format((int) ($companyRow['order_count'] ?? 0)); ?></td>
                        <td class="num ltv-strong">$<?php echo $money($companyRow['total_revenue'] ?? 0); ?></td>
                        <td class="num">$<?php echo $money($companyRow['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($companyRow['last_activity_time'] ?? 0); ?></td>
                        <td class="num" style="white-space: nowrap;">
                            <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvCompanyEdit(<?php echo $companyId; ?>);"><i class="fa fa-pencil"></i> Edit</button>
                            <?php $mergeTargetJson = json_encode([
                                'id' => $companyId,
                                'label' => (string) ($companyRow['name'] ?? ('#' . $companyId)),
                                'sub' => trim(implode(' · ', array_filter([(string) ($companyRow['domain'] ?? ''), '#' . $companyId]))),
                                'meta' => number_format((int) ($companyRow['contacts'] ?? 0)) . ' contact' . (((int) ($companyRow['contacts'] ?? 0)) === 1 ? '' : 's'),
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
                            <button type="button" class="ltv-btn ltv-btn-xs" onclick='ltvCompanyMerge(<?php echo $mergeTargetJson; ?>);' title="Merge another company into this one"><i class="fa fa-compress"></i> Merge</button>
                            <button type="button" class="ltv-btn ltv-btn-xs ltv-btn-danger" onclick="ltvCompanyDelete(<?php echo $companyId; ?>);">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php echo p202_ltv_pager($offset, $limit, (int) $list['total'], 'ltvCompaniesLoad'); ?>
    <div class="ltv-toolbar" style="padding-bottom: 12px; border-top: 1px solid #f0f1f2; padding-top: 12px;">
        <input type="text" class="ltv-input ltv-input-sm" id="ltv-new-company" maxlength="255" placeholder="New company name"
               onkeydown="if (event.key === 'Enter') { ltvCompanyAdd(); return false; }">
        <button type="button" class="ltv-btn ltv-btn-xs" onclick="ltvCompanyAdd();"><i class="fa fa-plus"></i> Add Company</button>
    </div>
<?php echo p202_ltv_card_close(); ?>

<script type="text/javascript">
    function ltvCompaniesLoad(offset) {
        ltvNav('companies', { offset: offset });
    }
    function ltvCompanyEdit(companyId) {
        ltvNav('companies', { edit: companyId, offset: <?php echo $offset; ?> });
    }
    function ltvCompanySave(companyId) {
        var row = $('#ltv-company-row-' + companyId);
        loadContentPost('<?php echo $selfUrl; ?>', {
            action: 'update_company',
            token: row.find('input[name=token]').val(),
            company_id: companyId,
            company_name: row.find('input[name=company_name]').val(),
            company_domain: row.find('input[name=company_domain]').val()
        });
    }
    function ltvCompanyAdd() {
        var name = $('#ltv-new-company').val();
        if (!name || !name.trim()) { return; }
        loadContentPost('<?php echo $selfUrl; ?>', {
            action: 'add_company',
            company_name: name.trim(),
            token: <?php echo json_encode($csrfToken); ?>
        });
    }
    function ltvCompanyMerge(target) {
        ltvMergeOpen({
            entity: 'company',
            noun: 'company',
            placeholder: 'Search companies by name or domain…',
            moves: 'all attached contacts (their company name is rewritten to the kept record)',
            target: target,
            confirm: function(keptId, goneId) {
                loadContentPost('<?php echo $selfUrl; ?>', {
                    action: 'merge_company',
                    company_id: keptId,
                    source_company_id: goneId,
                    token: <?php echo json_encode($csrfToken); ?>
                });
            }
        });
    }
    function ltvCompanyDelete(companyId) {
        if (!window.confirm('Delete this company? Only possible when no customers are attached.')) { return; }
        loadContentPost('<?php echo $selfUrl; ?>', {
            action: 'delete_company',
            company_id: companyId,
            token: <?php echo json_encode($csrfToken); ?>
        });
    }
    function ltvCompanyView(company) {
        ltvNav('company', { company: company });
    }
</script>

<?php require __DIR__ . '/ltv_merge_modal.php'; ?>
