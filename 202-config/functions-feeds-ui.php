<?php

declare(strict_types=1);

/**
 * The three sections that show a feed from my.tracking202.com — TV202, Hot
 * Deals (202-resources) and the App Store — on the v2 shell (U7).
 *
 * Each feed is read into plain rows here and the page renders the rows with
 * the component layer. Until U7 two of the pages printed the feed as it came:
 * TV202 echoed the remote HTML (Bootstrap 3 grid classes, 853px iframes) into
 * the page, and the App Store linked whatever the feed named. Now nothing
 * remote reaches the page as markup: a link is kept only when it is a web
 * address, a video only when it is a YouTube embed, and every text is
 * escaped by the page. A feed that cannot be read gives no rows, and the
 * page says so rather than showing an empty grid.
 *
 * Pure functions of their input, so tests/Standalone/FeedsUiTest.php calls
 * them without a network.
 */

/** The value as a web address (http or https), or null. */
function p202_feed_web_url(mixed $url): ?string
{
	if (!is_string($url)) {
		return null;
	}
	$url = trim($url);
	return preg_match('~^https?://[^\s"\'<>]+$~i', $url) === 1 ? $url : null;
}

/**
 * TV202's modules, read from the feed's HTML: a title (the module's <h6>),
 * a YouTube embed address and a one-line description (its <p>).
 *
 * @return list<array{title: string, embed: string, description: string}>
 */
function p202_tv_modules(string $html): array
{
	if (trim($html) === '') {
		return [];
	}
	$previous = libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	$loaded = $doc->loadHTML('<?xml encoding="utf-8"?><div id="p202-feed">' . $html . '</div>', LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	if (!$loaded) {
		return [];
	}
	$modules = [];
	foreach ($doc->getElementsByTagName('iframe') as $iframe) {
		$src = trim($iframe->getAttribute('src'));
		if (preg_match('~^https://www\.youtube(?:-nocookie)?\.com/embed/[A-Za-z0-9_-]+(?:\?[A-Za-z0-9_=&%.-]*)?$~', $src) !== 1) {
			continue;
		}
		// The module is the nearest ancestor that also holds a heading.
		$title = '';
		$description = '';
		for ($node = $iframe->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
			$headings = $node->getElementsByTagName('h6');
			if ($headings->length > 0) {
				$title = trim($headings->item(0)->textContent);
				$paragraphs = $node->getElementsByTagName('p');
				$description = $paragraphs->length > 0 ? trim($paragraphs->item(0)->textContent) : '';
				break;
			}
			if ($node->getAttribute('id') === 'p202-feed') {
				break;
			}
		}
		$modules[] = ['title' => $title !== '' ? $title : 'TV202 video', 'embed' => $src, 'description' => $description];
	}
	return $modules;
}

/**
 * Hot Deals: each deal the feed names with a title and a web address.
 *
 * @return list<array{title: string, image: ?string, description: string, url: string, coupon: string}>
 */
function p202_resource_deals(mixed $data): array
{
	if (!is_array($data) || !isset($data['deals']) || !is_array($data['deals'])) {
		return [];
	}
	$deals = [];
	foreach ($data['deals'] as $deal) {
		if (!is_array($deal)) {
			continue;
		}
		$url = p202_feed_web_url($deal['deal-url'] ?? $deal['url'] ?? null);
		$title = trim((string) ($deal['title'] ?? $deal['name'] ?? ''));
		if ($url === null || $title === '') {
			continue;
		}
		$deals[] = [
			'title' => $title,
			'image' => p202_feed_web_url($deal['deal-img'] ?? $deal['image'] ?? null),
			'description' => trim(strip_tags((string) ($deal['deal-description'] ?? $deal['description'] ?? ''))),
			'url' => $url,
			'coupon' => trim((string) ($deal['deal-coupon'] ?? $deal['coupon'] ?? '')),
		];
	}
	return $deals;
}

/**
 * The App Store's apps. An icon may be one of this install's own images
 * (`/202-img/…`), which is resolved against the install's base path.
 *
 * @return list<array{title: string, image: ?string, description: string, url: ?string, status: string, price: string, popular: bool}>
 */
function p202_appstore_apps(mixed $data, string $base): array
{
	if (!is_array($data) || !isset($data['deals']) || !is_array($data['deals'])) {
		return [];
	}
	$apps = [];
	foreach ($data['deals'] as $app) {
		if (!is_array($app)) {
			continue;
		}
		$title = trim((string) ($app['title'] ?? ''));
		if ($title === '') {
			continue;
		}
		$image = $app['app-img'] ?? null;
		if (is_string($image) && preg_match('~^/202-img/[A-Za-z0-9/_.-]+$~', $image) === 1 && !str_contains($image, '..')) {
			$image = rtrim($base, '/') . $image;
		} else {
			$image = p202_feed_web_url($image);
		}
		$description = trim((string) ($app['app-description'] ?? ''));
		if ($description === '') {
			$description = trim((string) ($app['app-descriptions'] ?? ''));
		}
		$apps[] = [
			'title' => $title,
			'image' => $image,
			'description' => $description,
			'url' => p202_feed_web_url($app['app-url'] ?? null),
			'status' => trim((string) ($app['app-install'] ?? '')),
			'price' => trim((string) ($app['app-price'] ?? '')),
			'popular' => ($app['app-status'] ?? $app['app-statusa'] ?? '') === 'popular',
		];
	}
	return $apps;
}
