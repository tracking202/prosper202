// Bot202 Facebook Pixel Assistant: a simple landing page's Meta Pixel events,
// as paid 1.9.56's fbpx.js and bot202_fb_pixel.php fired them. landing.php
// loads this when record.php's answer asks for it (window.p202FbPixelAssistant).
(function (w, d) {
	'use strict';
	if (w.p202FbPixel) { return; }
	var started = false;

	// Meta's Pixel base code: defines fbq and loads fbevents.js, unless the page already has it.
	function loadPixel() {
		if (w.fbq) { return; }
		var n = w.fbq = function () {
			if (n.callMethod) { n.callMethod.apply(n, arguments); } else { n.queue.push(arguments); }
		};
		if (!w._fbq) { w._fbq = n; }
		n.push = n;
		n.loaded = true;
		n.version = '2.0';
		n.queue = [];
		var t = d.createElement('script');
		t.async = true;
		t.src = 'https://connect.facebook.net/en_US/fbevents.js';
		var s = d.getElementsByTagName('script')[0];
		if (s && s.parentNode) { s.parentNode.insertBefore(t, s); } else { (d.head || d.documentElement).appendChild(t); }
	}

	function content(settings, label) {
		var data = {};
		if (settings.contentType) { data.content_type = settings.contentType; }
		if (settings.contentName) { data.content_name = settings.contentName; }
		if (settings.contentId) {
			data.content_ids = [settings.contentId];
			data.contents = [{id: settings.contentId, quantity: 1}];
		}
		data.t202_event = label;
		return data;
	}

	// A link out through the tracker (go.php or lp.php), or one PurLinks rewrote to the offer.
	function isOutbound(a, trackerHost) {
		try {
			if (a.hasAttribute && a.hasAttribute('data-p202-purlink')) { return true; }
			if (!a.href) { return false; }
			var url = new URL(a.href, d.baseURI);
			return url.hostname === trackerHost && /\/tracking202\/redirect\/(go|lp)\.php$/i.test(url.pathname);
		} catch (e) {
			return false;
		}
	}

	// settings: record.php's (FacebookPixelAssistantScript); mayFire: the page's consent, asked before each event.
	function start(settings, mayFire, trackerHost) {
		if (started || !settings || !/^[0-9]{5,20}$/.test(String(settings.pixelId)) || !mayFire()) { return false; }
		started = true;
		loadPixel();
		var pixel = String(settings.pixelId);
		var eventId = typeof settings.eventId === 'string' ? settings.eventId : '';
		var options = function (name) { return eventId ? {eventID: eventId + '-' + name} : {}; };

		w.fbq('init', pixel);
		w.fbq('trackSingle', pixel, 'PageView', {}, options('pageview'));
		w.fbq('trackSingle', pixel, 'ViewContent', content(settings, 'Landing Page View'), options('view'));

		if (settings.clickEvent !== 'ViewContent' && settings.clickEvent !== 'AddToCart') { return true; }
		var clicked = false;
		d.addEventListener('click', function (ev) {
			if (clicked) { return; }
			var el = ev.target;
			while (el && el.tagName !== 'A') { el = el.parentElement; }
			if (!el || !isOutbound(el, trackerHost) || !mayFire()) { return; }
			// Once per page view, as Meta would count one outbound click.
			clicked = true;
			var data = content(settings, 'Click To View');
			if (typeof settings.value === 'number' && isFinite(settings.value) && settings.value > 0
				&& /^[A-Z]{3}$/.test(String(settings.currency || ''))) {
				data.value = settings.value;
				data.currency = settings.currency;
			}
			w.fbq('trackSingle', pixel, settings.clickEvent, data, options('click'));
		}, true);
		return true;
	}

	w.p202FbPixel = {start: start};
})(window, document);
