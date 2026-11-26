<?php

declare(strict_types=1);
/** A request to https://my.tracking202.com (Http\VendorFetch): '' when refused or unanswered. */
function getUrl($url, $requestType = 'GET', $timeout = 30, $postArray = [], $headers = [], $userAgent = '', $connectTimeout = 5)
{
	return \Prosper202\Http\VendorFetch::fetch(
		(string) $url,
		(int) $timeout,
		(int) $connectTimeout,
		$requestType === 'POST' ? (array) $postArray : null,
		array_values((array) $headers),
		(string) $userAgent
	) ?? '';
}
