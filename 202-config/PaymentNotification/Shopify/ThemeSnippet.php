<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

/**
 * The script a store owner pastes into their Shopify theme. A buyer lands
 * on the store from a tracker whose URL ends ?t202subid=[[subid]]; the
 * script keeps that click id in the browser and adds it to every
 * add-to-cart form as the line item property _t202subid, which the order
 * webhook then carries (ShopifyAdapter) and the checkout does not show.
 */
final class ThemeSnippet
{
    public const PARAMETER = 't202subid';

    /** What the tracker's URL to the store ends with. */
    public const URL_SUFFIX = '?t202subid=[[subid]]';

    public static function html(): string
    {
        return <<<'HTML'
<script>
(function () {
  var KEY = 't202subid', DAYS = 30, id = null;
  var found = /[?&]t202subid=([^&#]*)/.exec(window.location.search);
  try {
    if (found && found[1]) {
      id = decodeURIComponent(found[1]);
      window.localStorage.setItem(KEY, JSON.stringify({ id: id, at: Date.now() }));
    } else {
      var kept = JSON.parse(window.localStorage.getItem(KEY) || 'null');
      if (kept && Date.now() - kept.at < DAYS * 864e5) { id = kept.id; }
    }
  } catch (e) {}
  if (typeof id !== 'string' || !/^[0-9a-f-]{1,64}$/.test(id)) { return; }
  function tag() {
    var forms = document.querySelectorAll('form[action*="/cart/add"]');
    for (var i = 0; i < forms.length; i++) {
      if (forms[i].querySelector('input[name="properties[_t202subid]"]')) { continue; }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'properties[_t202subid]';
      input.value = id;
      forms[i].appendChild(input);
    }
  }
  tag();
  document.addEventListener('DOMContentLoaded', tag);
  if (window.MutationObserver) {
    new MutationObserver(tag).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
</script>
HTML;
    }
}
