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

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';
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
                        // Name first, then domain — the first failure stops
                        // and reports, so a rename error is never masked by a
                        // successful domain write.
                        $companyId = (int) ($_POST['company_id'] ?? 0);
                        $companiesRepo->rename($userId, $companyId, (string) ($_POST['company_name'] ?? ''));
                        $companiesRepo->setDomain($userId, $companyId, (string) ($_POST['company_domain'] ?? ''));
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

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6>Companies <small><?php echo number_format((int) $list['total']); ?> account(s) — customers attach by company name or email domain</small></h6>
    </div>
</div>

<?php if ($notice !== null && $error === null) { ?>
    <div class="alert alert-success"><?php echo $esc($notice); ?></div>
<?php } ?>
<?php if ($error !== null) { ?>
    <div class="alert alert-danger"><?php echo $esc($error); ?></div>
<?php } ?>

<div class="row">
    <div class="col-xs-12">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Email Domain</th>
                    <th>Contacts</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>MRR</th>
                    <th>Last Activity</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list['rows'] === []) { ?>
                    <tr><td colspan="8"><em>No companies yet. They are created when a customer gets a company name,
                        by the nightly linking sweep, or manually below.</em></td></tr>
                <?php } ?>
                <?php foreach ($list['rows'] as $companyRow) {
                    $companyId = (int) $companyRow['company_id'];
                    if ($companyId === $editId) {
                ?>
                    <tr id="ltv-company-row-<?php echo $companyId; ?>">
                        <td>
                            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
                            <input type="hidden" name="company_id" value="<?php echo $companyId; ?>" />
                            <input type="text" class="form-control input-sm" name="company_name" maxlength="255"
                                value="<?php echo $esc($companyRow['name'] ?? ''); ?>">
                        </td>
                        <td><input type="text" class="form-control input-sm" name="company_domain" maxlength="191"
                                placeholder="example.com" value="<?php echo $esc($companyRow['domain'] ?? ''); ?>"></td>
                        <td><?php echo number_format((int) ($companyRow['contacts'] ?? 0)); ?></td>
                        <td><?php echo number_format((int) ($companyRow['order_count'] ?? 0)); ?></td>
                        <td>$<?php echo $money($companyRow['total_revenue'] ?? 0); ?></td>
                        <td>$<?php echo $money($companyRow['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($companyRow['last_activity_time'] ?? 0); ?></td>
                        <td class="text-right" style="white-space: nowrap;">
                            <button type="button" class="btn btn-xs btn-primary" onclick="ltvCompanySave(<?php echo $companyId; ?>);">Save</button>
                            <button type="button" class="btn btn-xs btn-default" onclick="ltvCompaniesLoad(<?php echo $offset; ?>);">Cancel</button>
                        </td>
                    </tr>
                <?php } else { ?>
                    <tr>
                        <td style="cursor: pointer;" onclick="ltvCompanyView(this.getAttribute('data-company'));"
                            data-company="<?php echo $esc($companyRow['name'] ?? ''); ?>" title="View engagement drill-down">
                            <a href="#" onclick="return false;"><?php echo $esc($companyRow['name'] ?? ''); ?></a>
                            <small class="text-muted">#<?php echo $companyId; ?></small>
                        </td>
                        <td><?php echo $esc($companyRow['domain'] ?? '') ?: '—'; ?></td>
                        <td><?php echo number_format((int) ($companyRow['contacts'] ?? 0)); ?></td>
                        <td><?php echo number_format((int) ($companyRow['order_count'] ?? 0)); ?></td>
                        <td>$<?php echo $money($companyRow['total_revenue'] ?? 0); ?></td>
                        <td>$<?php echo $money($companyRow['mrr'] ?? 0); ?></td>
                        <td><?php echo $when($companyRow['last_activity_time'] ?? 0); ?></td>
                        <td class="text-right" style="white-space: nowrap;">
                            <button type="button" class="btn btn-xs btn-default" onclick="ltvCompanyEdit(<?php echo $companyId; ?>);">Edit</button>
                            <button type="button" class="btn btn-xs btn-default" onclick="ltvCompanyMerge(<?php echo $companyId; ?>);" title="Merge another company into this one">Merge</button>
                            <button type="button" class="btn btn-xs btn-danger" onclick="ltvCompanyDelete(<?php echo $companyId; ?>);">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
                <?php } ?>
            </tbody>
        </table>

        <div class="text-center">
            <?php if ($offset > 0) { ?>
                <a href="#" onclick="ltvCompaniesLoad(<?php echo max(0, $offset - $limit); ?>); return false;">&laquo; Previous</a>
            <?php } ?>
            <?php if ($offset + $limit < (int) $list['total']) { ?>
                &nbsp;<a href="#" onclick="ltvCompaniesLoad(<?php echo $offset + $limit; ?>); return false;">Next &raquo;</a>
            <?php } ?>
        </div>

        <div class="form-inline" style="margin-top: 10px;">
            <input type="text" class="form-control input-sm" id="ltv-new-company" maxlength="255" placeholder="New company name">
            <button type="button" class="btn btn-sm btn-default" onclick="ltvCompanyAdd();">Add Company</button>
        </div>
    </div>
</div>

<script type="text/javascript">
    function ltvCompaniesLoad(offset) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', { offset: offset })
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanyEdit(companyId) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', { edit: companyId, offset: <?php echo $offset; ?> })
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanySave(companyId) {
        var element = $('#m-content');
        var row = $('#ltv-company-row-' + companyId);
        $.post('<?php echo $selfUrl; ?>', {
            action: 'update_company',
            token: row.find('input[name=token]').val(),
            company_id: companyId,
            company_name: row.find('input[name=company_name]').val(),
            company_domain: row.find('input[name=company_domain]').val()
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanyAdd() {
        var name = $('#ltv-new-company').val();
        if (!name || !name.trim()) { return; }
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {
            action: 'add_company',
            company_name: name.trim(),
            token: <?php echo json_encode($csrfToken); ?>
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanyMerge(companyId) {
        var sourceId = window.prompt('Merge which company # INTO this one?\n\nIts contacts move here (their company name is rewritten) and the other company is removed.');
        if (!sourceId || !/^\d+$/.test(sourceId.trim())) { return; }
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {
            action: 'merge_company',
            company_id: companyId,
            source_company_id: sourceId.trim(),
            token: <?php echo json_encode($csrfToken); ?>
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanyDelete(companyId) {
        if (!window.confirm('Delete this company? Only possible when no customers are attached.')) { return; }
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {
            action: 'delete_company',
            company_id: companyId,
            token: <?php echo json_encode($csrfToken); ?>
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvCompanyView(company) {
        var element = $('#m-content');
        $.post('<?php echo get_absolute_url(); ?>tracking202/ajax/ltv_company.php', {
            company: company
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
</script>
