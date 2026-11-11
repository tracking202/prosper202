// Loader for landing-page snippets issued by paid 1.9.56:
// <script src=".../tracking202/static/landing.js?lpip=N" id="t202js" async>.
// It loads landing.php exactly as the current Get LP Code snippet does.
(function (d) {
  var me = d.currentScript || d.getElementById('t202js');
  if (!me || !me.src) {
    return;
  }
  // Loaded again (1.9.56 pages reload it on pageshow after Back): the page is
  // already tracked, so only its PurLinks are refreshed. No second pageview.
  if (d.getElementById('upxif')) {
    if (typeof window.updatePurLink === 'function') { window.updatePurLink(); }
    return;
  }
  var m = me.src.match(/^(.*\/tracking202\/static\/)landing\.js(?:\?([^#]*))?/i);
  if (!m) {
    return;
  }
  // defpixel=1 (the deferred pixel) is carried over; rsid is dropped.
  var own = new URLSearchParams(m[2] || '');
  var lpip = own.get('lpip') || '';
  var defpixel = own.get('defpixel') === '1' ? '&p202_defpixel=1' : '';
  // landing.php's own p202_* flags (p202_purlink=0, p202_defpixel=1) pass through as written.
  var flags = '';
  (m[2] || '').split('&').forEach(function (pair) {
    var name;
    try { name = decodeURIComponent(pair.split('=')[0].replace(/\+/g, ' ')); } catch (e) { return; }
    if (/^p202_/.test(name.replace(/^ +/, '').replace(/[ .]/g, '_'))) { flags += '&' + pair; }
  });
  var t202id = new URLSearchParams(window.location.search).get('t202id') || '';
  var s = d.createElement('script');
  s.src = m[1] + 'landing.php?lpip=' + encodeURIComponent(lpip) + '&t202id=' + encodeURIComponent(t202id) + defpixel + flags;
  s.async = true;
  s.id = 'upxif';
  var first = d.getElementsByTagName('script')[0];
  first.parentNode.insertBefore(s, first);
})(document);
