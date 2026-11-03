// Loader for landing-page snippets issued by paid 1.9.56:
// <script src=".../tracking202/static/landing.js?lpip=N" id="t202js" async>.
// It loads landing.php exactly as the current Get LP Code snippet does.
(function (d) {
  var me = d.currentScript || d.getElementById('t202js');
  if (!me || !me.src || d.getElementById('upxif')) {
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
  var t202id = new URLSearchParams(window.location.search).get('t202id') || '';
  var s = d.createElement('script');
  s.src = m[1] + 'landing.php?lpip=' + encodeURIComponent(lpip) + '&t202id=' + encodeURIComponent(t202id) + defpixel;
  s.async = true;
  s.id = 'upxif';
  var first = d.getElementsByTagName('script')[0];
  first.parentNode.insertBefore(s, first);
})(document);
