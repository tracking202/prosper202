<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-14) . '/202-config/connect.php');

AUTH::require_user();

template_top('Prosper202 ClickServer App Store');  ?>

<style>
/* Base Layout */
.resources-header {
    margin-bottom: 32px;
}
.resources-header h4 {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
}
.resources-header p {
    color: #64748b;
    font-size: 15px;
    line-height: 1.6;
    margin: 0;
    max-width: 680px;
}

/* Grid System */
.resources-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}
@media (max-width: 992px) {
    .resources-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}

/* Card Component */
.resource-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.03);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
}
.resource-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 6px rgba(0,0,0,0.04), 0 12px 32px rgba(0,0,0,0.08);
    transform: translateY(-3px);
}

/* Card Content Layout */
.resource-card .card-content {
    display: flex;
    gap: 20px;
    flex: 1;
}

/* Image Styling */
.resource-card .resource-img-link {
    flex-shrink: 0;
    text-decoration: none;
    border-radius: 10px;
    overflow: hidden;
    transition: transform 0.2s ease;
}
.resource-card .resource-img-link:hover {
    transform: scale(1.03);
}
.resource-card .resource-img-link:focus {
    outline: 2px solid #007bff;
    outline-offset: 2px;
}
.resource-card .resource-img {
    width: 88px;
    height: 88px;
    object-fit: contain;
    border-radius: 10px;
    background: linear-gradient(145deg, #f8fafc, #f1f5f9);
    padding: 12px;
    display: block;
    border: 1px solid #e2e8f0;
}

/* Text Content */
.resource-card .card-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.resource-card .card-title {
    text-decoration: none;
    display: block;
    margin-bottom: 8px;
}
.resource-card .card-title:hover h5 {
    color: #007bff;
}
.resource-card .card-title:focus {
    outline: none;
}
.resource-card .card-title:focus h5 {
    color: #007bff;
    text-decoration: underline;
}
.resource-card h5 {
    margin: 0;
    font-weight: 600;
    color: #1e293b;
    font-size: 17px;
    line-height: 1.4;
    transition: color 0.15s ease;
}
.resource-card .description {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin: 0 0 16px 0;
    flex-grow: 1;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
}

/* Actions Area */
.resource-card .card-actions {
    margin-top: auto;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding-top: 4px;
}

/* Primary Button */
.resource-card .btn-resource {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 22px;
    background: linear-gradient(135deg, #007bff 0%, #0062cc 100%);
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,123,255,0.2);
    min-width: 110px;
}
.resource-card .btn-resource:hover {
    background: linear-gradient(135deg, #0069d9 0%, #0056b3 100%);
    box-shadow: 0 4px 12px rgba(0,123,255,0.35);
    transform: translateY(-1px);
    color: #fff;
    text-decoration: none;
}
.resource-card .btn-resource:focus {
    outline: 2px solid #007bff;
    outline-offset: 2px;
}
.resource-card .btn-resource:active {
    transform: translateY(0);
}

/* Coupon Code */
.resource-card .coupon-wrapper {
    position: relative;
    display: inline-flex;
    text-decoration: none;
}
.resource-card .coupon-code {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: #f0f9ff;
    color: #0369a1;
    border: 1px dashed #7dd3fc;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.2s ease;
}
.resource-card .coupon-code::before {
    content: '';
    width: 14px;
    height: 14px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%230369a1'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'%3E%3C/path%3E%3C/svg%3E");
    background-size: contain;
    flex-shrink: 0;
}
.resource-card .coupon-wrapper:hover .coupon-code {
    background: #0369a1;
    color: #fff;
    border-color: #0369a1;
}
.resource-card .coupon-wrapper:hover .coupon-code::before {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23ffffff'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'%3E%3C/path%3E%3C/svg%3E");
}
.resource-card .coupon-wrapper:focus .coupon-code {
    outline: 2px solid #007bff;
    outline-offset: 2px;
}

/* Tooltip */
.resource-card .coupon-tooltip {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 0;
    padding: 10px 14px;
    background: #1e293b;
    color: #f8fafc;
    border-radius: 8px;
    font-size: 12px;
    font-family: 'SF Mono', SFMono-Regular, Consolas, monospace;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    z-index: 1000;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    width: max-content;
    max-width: 300px;
    line-height: 1.5;
    text-align: left;
}
.resource-card .coupon-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 20px;
    border: 7px solid transparent;
    border-top-color: #1e293b;
}
.resource-card .coupon-wrapper:hover .coupon-tooltip {
    opacity: 1;
    visibility: visible;
}

/* Empty State */
.resources-empty {
    text-align: center;
    padding: 48px 24px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px dashed #cbd5e1;
}
.resources-empty strong {
    display: block;
    color: #475569;
    font-size: 16px;
    margin-bottom: 4px;
}
.resources-empty span {
    color: #94a3b8;
    font-size: 14px;
}
</style>

<div class="row home">
    <div class="col-xs-12 resources-header">
        <h4>Resources</h4>
        <p>A curated collection of tools and services to help you become a better internet marketer. Updated frequently with new deals and offers.</p>
    </div>
</div>

<?php
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
if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($result, true);
}

if ($data && isset($data['deals']) && is_array($data['deals'])) { ?>
<div class="resources-grid">
<?php foreach ($data['deals'] as $deal) {
    if (is_object($deal)) {
        $deal = (array) $deal;
    }
    if (!is_array($deal)) {
        continue;
    }
    $title = $deal['title'] ?? $deal['name'] ?? '';
    $image = $deal['deal-img'] ?? $deal['image'] ?? '';
    $description = strip_tags((string) ($deal['deal-description'] ?? $deal['description'] ?? ''));
    $url = $deal['deal-url'] ?? $deal['url'] ?? '';
    $coupon = $deal['deal-coupon'] ?? $deal['coupon'] ?? '';
    if ($title === '' && $image === '' && $description === '' && $url === '' && $coupon === '') {
        continue;
    } ?>
    <div class="resource-card">
        <div class="card-content">
            <?php if ($image !== '') { ?>
            <a href="<?php echo htmlspecialchars((string) $url);?>" target="_blank" rel="noopener" class="resource-img-link" aria-label="<?php echo htmlspecialchars((string) $title);?>">
                <img src="<?php echo htmlspecialchars((string) $image);?>" class="resource-img" alt="" loading="lazy">
            </a>
            <?php } ?>
            <div class="card-text">
                <a href="<?php echo htmlspecialchars((string) $url);?>" target="_blank" rel="noopener" class="card-title">
                    <h5><?php echo htmlspecialchars((string) $title);?></h5>
                </a>
                <?php if ($description !== '') { ?>
                <p class="description"><?php echo htmlspecialchars((string) $description);?></p>
                <?php } ?>
                <?php if ($url !== '') { ?>
                <div class="card-actions">
                    <?php if ($coupon !== '') { ?>
                    <a href="<?php echo htmlspecialchars((string) $url);?>" target="_blank" rel="noopener" class="coupon-wrapper" aria-label="Coupon: <?php echo htmlspecialchars((string) $coupon);?>">
                        <span class="coupon-code"><?php echo htmlspecialchars((string) $coupon);?></span>
                        <span class="coupon-tooltip" role="tooltip"><?php echo htmlspecialchars((string) $coupon);?></span>
                    </a>
                    <?php } ?>
                    <a href="<?php echo htmlspecialchars((string) $url);?>" target="_blank" rel="noopener" class="btn-resource">
                        <?php echo $coupon !== '' ? 'Get Deal' : 'Visit Site'; ?>
                    </a>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
<?php } ?>
</div>
<?php } else { ?>
<div class="resources-empty">
    <strong>Resources temporarily unavailable</strong>
    <span>Please check back in a few moments.</span>
</div>
<?php } ?>
<?php template_bottom(); ?>
