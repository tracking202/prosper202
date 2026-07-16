/**
 * Prosper202 one-time EU consent prompt.
 *
 * Mounted by 202-config/template.php only when ConsentPolicy::needsEuPrompt()
 * says so (EU user, analytics consent unset, prompt not yet seen). Posts the
 * choice to 202-account/ajax/messaging/consent.php with source=eu_prompt and
 * removes itself. Styled with the global design system (.card-modern,
 * .btn-modern) on p202-ui.css tokens — no bespoke palette.
 */
(function () {
    'use strict';

    var mount = document.getElementById('p202-consent-banner');
    if (!mount) { return; }

    var base = mount.getAttribute('data-base') || '/';
    var token = mount.getAttribute('data-token') || '';

    var card = document.createElement('div');
    card.className = 'card-modern';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', 'Data preferences');
    // Fixed positioning is the one pattern the kit lacks; everything else
    // (surface, radius, shadow) comes from p202-ui.css tokens.
    card.style.position = 'fixed';
    card.style.right = '24px';
    card.style.bottom = '24px';
    card.style.maxWidth = '420px';
    card.style.zIndex = '10050';
    card.style.borderRadius = 'var(--p202-r-xl)';
    card.style.boxShadow = 'var(--p202-sh-pop)';

    var headline = document.createElement('h4');
    headline.textContent = 'Get workflow tips & higher-paying offers';
    headline.style.marginTop = '0';

    var body = document.createElement('p');
    body.style.fontSize = '13px';
    body.textContent = 'Allow product analytics so we can surface workflow tips and ' +
        'match you with specially-sourced, higher-paying offers relevant to what you ' +
        'promote. Your usage data — including traffic stats, revenue numbers, and ' +
        'campaign names + destination links — is sent to Prosper202. We never share ' +
        'it with third parties.';

    var actions = document.createElement('div');
    actions.style.display = 'flex';
    actions.style.gap = '12px';
    actions.style.alignItems = 'center';

    var accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'btn-modern';
    accept.textContent = 'Accept';

    var decline = document.createElement('button');
    decline.type = 'button';
    decline.textContent = 'No thanks';
    decline.style.background = 'none';
    decline.style.border = 'none';
    decline.style.cursor = 'pointer';
    decline.style.fontSize = '14px';
    decline.style.color = 'var(--p202-accent)';

    function remove() {
        if (mount.parentNode) { mount.parentNode.removeChild(mount); }
    }

    function send(state) {
        var params = new URLSearchParams();
        params.append('flag', 'analytics');
        params.append('state', state);
        params.append('source', 'eu_prompt');
        params.append('token', token);
        fetch(base + '202-account/ajax/messaging/consent.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).catch(function () {
            /* Never break the host page; the prompt simply reappears next load. */
        });
        remove();
    }

    accept.addEventListener('click', function () { send('granted'); });
    decline.addEventListener('click', function () { send('denied'); });

    actions.appendChild(accept);
    actions.appendChild(decline);
    card.appendChild(headline);
    card.appendChild(body);
    card.appendChild(actions);
    mount.appendChild(card);
})();
