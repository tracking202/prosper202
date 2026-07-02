<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

/**
 * Product catalog view: every product ingested from orders, with lifetime
 * revenue aggregated from line items, plus catalog editing (name/sku/price)
 * and delete-if-unreferenced. Line items snapshot product_name at sale time,
 * so edits here never rewrite order history.
 */

$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');

$money = static fn (mixed $v): string => number_format((float) $v, 2);
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => ((int) $ts) > 0 ? date('M j, Y', (int) $ts) : '—';

$backUrl = get_absolute_url() . 'tracking202/ajax/sort_ltv.php';
$selfUrl = get_absolute_url() . 'tracking202/ajax/ltv_products.php';

$notice = null;
$error = null;

try {
    $conn = new \Prosper202\Database\Connection($db);

    if ($action !== '') {
        if (!AUTH::check_csrf_token()) {
            $error = 'Your session token was invalid — please try again.';
        } else {
            try {
                switch ($action) {
                    case 'save_product':
                        $productId = (int) ($_POST['product_id'] ?? 0);
                        $name = trim((string) ($_POST['product_name'] ?? ''));
                        $sku = trim((string) ($_POST['product_sku'] ?? ''));
                        $priceRaw = trim((string) ($_POST['product_price'] ?? ''));
                        if ($productId <= 0) {
                            throw new \RuntimeException('Product not found.');
                        }
                        if ($name === '') {
                            throw new \RuntimeException('Product name must not be empty.');
                        }
                        $price = null;
                        if ($priceRaw !== '') {
                            if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                                throw new \RuntimeException('Price must be a non-negative number.');
                            }
                            $price = (float) $priceRaw;
                        }
                        $stmt = $conn->prepareWrite(
                            'UPDATE 202_products SET name = ?, sku = ?, price = ?, updated_at = ?
                             WHERE product_id = ? AND user_id = ?'
                        );
                        $conn->bind($stmt, 'ssdiii', [
                            mb_substr($name, 0, 255),
                            $sku !== '' ? mb_substr($sku, 0, 191) : null,
                            $price,
                            time(),
                            $productId,
                            $userId,
                        ]);
                        if ($conn->executeUpdate($stmt) === 0) {
                            // Zero rows can mean "unchanged", so verify existence
                            // before calling it an error.
                            $check = $conn->prepareRead(
                                'SELECT product_id FROM 202_products WHERE product_id = ? AND user_id = ? LIMIT 1'
                            );
                            $conn->bind($check, 'ii', [$productId, $userId]);
                            if ($conn->fetchOne($check) === null) {
                                throw new \RuntimeException('Product not found.');
                            }
                        }
                        $notice = 'Product updated. Past order line items keep their sale-time snapshot.';
                        break;

                    case 'delete_product':
                        $productId = (int) ($_POST['product_id'] ?? 0);
                        $check = $conn->prepareRead(
                            'SELECT COUNT(*) AS c FROM 202_revenue_line_items WHERE product_id = ? AND user_id = ?'
                        );
                        $conn->bind($check, 'ii', [$productId, $userId]);
                        $refs = $conn->fetchOne($check);
                        if (((int) ($refs['c'] ?? 0)) > 0) {
                            throw new \RuntimeException(
                                'This product appears on ' . (int) $refs['c'] . ' order line item(s) and cannot be deleted.'
                            );
                        }
                        $stmt = $conn->prepareWrite(
                            'DELETE FROM 202_products WHERE product_id = ? AND user_id = ?'
                        );
                        $conn->bind($stmt, 'ii', [$productId, $userId]);
                        if ($conn->executeUpdate($stmt) === 0) {
                            throw new \RuntimeException('Product not found.');
                        }
                        $notice = 'Product deleted.';
                        break;

                    default:
                        throw new \RuntimeException('Unknown action.');
                }
            } catch (\RuntimeException $actionError) {
                $error = $actionError->getMessage();
            }
        }
    }

    $stmt = $conn->prepareRead(
        'SELECT p.product_id, p.external_product_id, p.sku, p.name, p.price, p.currency,
                p.created_at, p.updated_at,
                COALESCE(li.revenue, 0) AS revenue,
                COALESCE(li.orders, 0) AS orders,
                COALESCE(li.units, 0) AS units
         FROM 202_products p
         LEFT JOIN (
            SELECT product_id, SUM(amount) AS revenue, COUNT(DISTINCT event_id) AS orders, SUM(quantity) AS units
            FROM 202_revenue_line_items
            WHERE user_id = ? AND product_id IS NOT NULL
            GROUP BY product_id
         ) li ON li.product_id = p.product_id
         WHERE p.user_id = ?
         ORDER BY revenue DESC, p.product_id ASC
         LIMIT 200'
    );
    $conn->bind($stmt, 'ii', [$userId, $userId]);
    $products = $conn->fetchAll($stmt);
} catch (\Throwable $e) {
    error_log('ltv_products: ' . $e->getMessage());
    echo '<div class="alert alert-warning">Product data is unavailable. Run the LTV migration if you have not yet.</div>';
    return;
}

$csrfToken = (string) ($_SESSION['token'] ?? '');
$editId = ($error !== null && $action === 'save_product') ? (int) ($_POST['product_id'] ?? 0) : (int) ($_POST['edit'] ?? 0);
?>

<div class="row" style="margin-bottom: 10px;">
    <div class="col-xs-12">
        <a href="#" onclick="loadContent('<?php echo $backUrl; ?>', null); return false;">&laquo; Back to Customer LTV</a>
    </div>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-xs-12">
        <h6>Product Catalog <small><?php echo count($products); ?> product(s) — created automatically from order line items</small></h6>
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
                    <th>Product</th>
                    <th>SKU</th>
                    <th>External ID</th>
                    <th>List Price</th>
                    <th>Orders</th>
                    <th>Units</th>
                    <th>Revenue</th>
                    <th>First Seen</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($products === []) { ?>
                    <tr><td colspan="9"><em>No products yet — they appear when conversions or API revenue include line items.</em></td></tr>
                <?php } ?>
                <?php foreach ($products as $product) {
                    $productId = (int) $product['product_id'];
                    if ($productId === $editId) {
                ?>
                    <tr id="ltv-product-row-<?php echo $productId; ?>">
                        <td>
                            <input type="hidden" name="token" value="<?php echo $esc($csrfToken); ?>" />
                            <input type="hidden" name="action" value="save_product" />
                            <input type="hidden" name="product_id" value="<?php echo $productId; ?>" />
                            <input type="text" class="form-control input-sm" name="product_name" maxlength="255"
                                value="<?php echo $esc($product['name'] ?? ''); ?>">
                        </td>
                        <td><input type="text" class="form-control input-sm" name="product_sku" maxlength="191"
                                value="<?php echo $esc($product['sku'] ?? ''); ?>"></td>
                        <td><?php echo $esc($product['external_product_id'] ?? '') ?: '—'; ?></td>
                        <td><input type="text" class="form-control input-sm" name="product_price" size="8"
                                value="<?php echo ($product['price'] ?? null) !== null ? $esc($product['price']) : ''; ?>"></td>
                        <td><?php echo number_format((int) ($product['orders'] ?? 0)); ?></td>
                        <td><?php echo number_format((float) ($product['units'] ?? 0)); ?></td>
                        <td>$<?php echo $money($product['revenue'] ?? 0); ?></td>
                        <td><?php echo $when($product['created_at'] ?? 0); ?></td>
                        <td class="text-right" style="white-space: nowrap;">
                            <button type="button" class="btn btn-xs btn-primary" onclick="ltvProductSave(<?php echo $productId; ?>);">Save</button>
                            <button type="button" class="btn btn-xs btn-default" onclick="ltvProductsReload();">Cancel</button>
                        </td>
                    </tr>
                <?php } else { ?>
                    <tr>
                        <td><?php echo $esc($product['name'] ?? ''); ?></td>
                        <td><?php echo $esc($product['sku'] ?? '') ?: '—'; ?></td>
                        <td><?php echo $esc($product['external_product_id'] ?? '') ?: '—'; ?></td>
                        <td><?php echo ($product['price'] ?? null) !== null ? '$' . $money($product['price']) : '—'; ?></td>
                        <td><?php echo number_format((int) ($product['orders'] ?? 0)); ?></td>
                        <td><?php echo number_format((float) ($product['units'] ?? 0)); ?></td>
                        <td>$<?php echo $money($product['revenue'] ?? 0); ?></td>
                        <td><?php echo $when($product['created_at'] ?? 0); ?></td>
                        <td class="text-right" style="white-space: nowrap;">
                            <button type="button" class="btn btn-xs btn-default" onclick="ltvProductEdit(<?php echo $productId; ?>);">Edit</button>
                            <button type="button" class="btn btn-xs btn-danger" onclick="ltvProductDelete(<?php echo $productId; ?>);">Delete</button>
                        </td>
                    </tr>
                <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script type="text/javascript">
    function ltvProductsReload() {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {})
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvProductEdit(productId) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', { edit: productId })
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvProductSave(productId) {
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', $('#ltv-product-row-' + productId).find('input').serialize())
            .done(function(data) { element.html(data).css('opacity', '1'); });
    }
    function ltvProductDelete(productId) {
        if (!window.confirm('Delete this product? Only possible when no order line items reference it.')) { return; }
        var element = $('#m-content');
        $.post('<?php echo $selfUrl; ?>', {
            action: 'delete_product',
            product_id: productId,
            token: <?php echo json_encode($csrfToken); ?>
        }).done(function(data) { element.html(data).css('opacity', '1'); });
    }
</script>
