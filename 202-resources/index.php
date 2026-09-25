<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-14) . '/202-config/connect.php');
require_once dirname(__DIR__) . '/202-config/functions-feeds-ui.php';

AUTH::require_user();

$feedUrl = 'https://my.tracking202.com/feed/resources/';
$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_URL, $feedUrl);
$result = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = null;
if (is_string($result) && $httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($result, true);
}
$deals = p202_resource_deals($data);
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

template_top('Prosper202 ClickServer Hot Deals', ['ui' => 'v2']);  ?>

<div class="p202-page-header">
    <div class="p202-page-header__icon"><i class="bi bi-star"></i></div>
    <div class="p202-page-header__text">
        <h1 class="p202-page-header__title">Hot deals &amp; discounts</h1>
        <p class="p202-page-header__desc">Tools and services for affiliate marketers, with the discounts Prosper202 users get. Updated often.</p>
    </div>
</div>

<?php if ($deals === []) { ?>
<div class="p202-empty">
    <i class="bi bi-bag p202-empty__icon"></i>
    <strong class="p202-empty__title">Deals are temporarily unavailable</strong>
    <div>The deals feed did not answer. Please check back in a few moments.</div>
    <div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-resources/'); ?>">Try again</a></div>
</div>
<?php } else { ?>
<div class="row g-4" id="resource-deals">
<?php foreach ($deals as $deal) { ?>
    <div class="col-12 col-lg-6">
        <section class="p202-panel h-100">
            <div class="p202-panel__body d-flex gap-3">
                <?php if ($deal['image'] !== null) { ?>
                <a href="<?php echo $e($deal['url']); ?>" target="_blank" rel="noopener" class="flex-shrink-0" tabindex="-1" aria-hidden="true">
                    <img src="<?php echo $e($deal['image']); ?>" alt="" width="88" height="88" class="rounded border object-fit-contain p-2 bg-white" loading="lazy">
                </a>
                <?php } ?>
                <div class="d-flex flex-column flex-grow-1">
                    <h2 class="h6 mb-2"><a href="<?php echo $e($deal['url']); ?>" target="_blank" rel="noopener"><?php echo $e($deal['title']); ?></a></h2>
                    <?php if ($deal['description'] !== '') { ?>
                    <p class="small text-secondary mb-2"><?php echo $e($deal['description']); ?></p>
                    <?php } ?>
                    <?php if ($deal['coupon'] !== '') { ?>
                    <p class="small fw-bold text-primary-emphasis mb-3"><i class="bi bi-tag" aria-hidden="true"></i> <?php echo $e($deal['coupon']); ?></p>
                    <?php } ?>
                    <div class="mt-auto">
                        <a href="<?php echo $e($deal['url']); ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm"><?php echo $deal['coupon'] !== '' ? 'Get the deal' : 'Visit site'; ?></a>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php } ?>
</div>
<?php } ?>
<?php template_bottom(); ?>
